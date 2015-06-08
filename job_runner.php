<?php
/*
This code connects to a database and pulls a list of shell commands from a MySQL table (pending_jobs).
Each command is executed in parallel using queues and workers, and the output is
saved to another MySQL table (completed_jobs), a flat file, and a Redis caching layer (using php_redis extension).
The Redis cache is set to expire 10 seconds after the data is stored.
Each command that executed without error is deleted from the pending_jobs table.

Later, saved output from the shell commands is requested and displayed. The output is retrieved from the Redis
server if it still remains in the cache, or from the MySQL database if the cache has expired.

There are many PHP libraries for managing queues and workers such as
Resque, Gearman, IronWorker, php-amqplib/RabbitMQ, etc.
For the purpose of demonstration I did not use any of these third-part libraries.
The PHP "Pool", "Worker", and "Stackable" pthreads classes will be used instead.

A thread-safe installation of PHP is required.
Note: Since this is a demonstration of concepts and not production-ready code I did not attempt
to avoid SQL injection or take any steps to prevent execution of malicious code.

Here are the mysql tables used:

CREATE TABLE `pending_jobs` (
`command` varchar(255) NOT NULL ,
`args`  varchar(255) DEFAULT NULL ,
`processing`  tinyint(1) UNSIGNED NOT NULL DEFAULT 0 ,
`id`  int(10) UNSIGNED NOT NULL AUTO_INCREMENT ,
PRIMARY KEY (`id`)
)

CREATE TABLE `completed_jobs` (
`command`  varchar(255) NOT NULL ,
`args`  varchar(255) NULL DEFAULT NULL ,
`output`  text NULL ,
`time_completed`  datetime NOT NULL ,
`job_id`  int(10) NOT NULL ,
`id`  int(10) UNSIGNED NOT NULL AUTO_INCREMENT ,
PRIMARY KEY (`id`)
)
 */

define("DB_HOST", "localhost");
define("DB_USERNAME", "root");
define("DB_PASSWORD", "password");
define("DB_DATABASE", "test");
define("DB_PORT", "3306");

define('REDIS_HOST', '127.0.0.1'); //no authentication for redis server

define('FLAT_FILE', 'completed_jobs.txt');


class DBWork extends Stackable
{
    public $id; //holds the row id of the job
    public $complete; //boolean value that represents whether the job is complete (for garbage collector)
    private $command; //holds the command + arguments string

    /* transfer the job to this object's scope, build the command string, and set some defaults. */
    public function __construct($job, JobRunner $jobRunner)
    {
        $this->job = $job;
        $this->jobRunner = $jobRunner;
        if ($job['args']) {
            $this->command = $job['command'] . ' ' . $job['args'];
        } else {
            $this->command = $job['command'];
        }
        $this->id = $job['id'];
        $this->complete = false;
    }

    /* implements the Stackable Object's "run" function */
    public function run()
    {
        //run the actual command we pulled from the database
        list($error, $output) = $this->execute($this->command);

        /* if the command executed without error then:
        1) insert the job metadata and command output into the `completed_jobs` table
        2) insert the job metadata and command output into the "completed_jobs.txt" flat file
        3) delete the job from the `pending_jobs` queue (database table)

        otherwise, unlock the job in the database.
         */

        if (!$error) {
            try {
                /* now that the job has executed successfully,
                create a copy of the job object and include the output,
                the job's id, and the time when execution finished
                for storage in a flat file and in the database */
                $completed_job = [];
                foreach ($this->job as $key => $value) {
                    $completed_job[$key] = $value;
                }
                $completed_job['output'] = $output;
                $completed_job['time_completed'] = date("Y-m-d H:i:s");
                $completed_job['job_id'] = $this->job['id'];

                //don't need the id field anymore
                unset($completed_job['id']);

                //save and delete from pending
                $this->jobRunner->save_flat_completed($completed_job);
                $this->jobRunner->save_db_completed($completed_job);
                //cache the completed job in redis using the job_id as the key
                $this->jobRunner->cache_completed_job($completed_job);
                $this->jobRunner->delete_job($completed_job['job_id']);
                $this->complete = true;

            } catch (Exception $e) {
                //screwed something up? unlock the job in the db.
                echo 'Caught exception: ', $e->getMessage(), "\n";
                $this->jobRunner->unlock_job($this->id);
            }

        } else {
            //command returned an error? unlock the job in the db.
            $this->jobRunner->unlock_job($this->id);
        }
    }

    //is the job done?

    private function execute($command)
    {
        $return_var = null;
        $output = null;

        //I realize this is super unsafe.
        //restrictions should be placed on this in production-ready code.

        exec($command, $output, $return_var);

        //let the shell user know whats going on
        echo sprintf("command: \n\t%s\noutput: \n\t%s\n", $command, implode(',', $output));

        return [$return_var, implode(',', $output)];


    }

    public function isComplete()
    {
        return $this->complete;
    }

}

/*
defines the DBWorker class as an extension of Worker.
the constructor requires a parameter of class Stackable.
this allows the worker to be added to the pool while maintaining a reference to the command-runner
 */

class DBWorker extends Worker
{

    protected $runner;

    public function __construct(DBWork $runner)
    {
        $this->runner = $runner;
    }
}

/*
passes the command straight to the shell and returns the output as well as the exit code.
the command is executed in a multi-threaded/parallel fashion.
 */

class JobRunner
{
    var $mysqli;
    var $redis;
    var $runner;
    var $pool;

    public function __construct(Stackable $dbwork = null)
    {
        if ($dbwork instanceof Stackable) {
            $this->submit($dbwork);
        }
    }


    /**
     * @param Stackable $dbwork
     */
    public function submit(Stackable $dbwork)
    {
        if ($dbwork instanceof Stackable) {
            $this->runner = $dbwork;
        }

        if (!$this->pool instanceof Pool) {
            /*
            This is our pool of workers with (max 10 workers).
             */
            $this->pool = new Pool(10, DBWorker::class, [$this->runner]);
        }

        $this->pool->submit($dbwork);
    }


    /*
    get one job from jobs table.
    if successful then lock the job in the table and return it.
    optionally, pass the job's row id to get the specific job
     */
    public function get_job($job_id = false)
    {

        $sql_and = '';
        if ($job_id !== false) {
            $sql_and = "AND id=$job_id";
        }
        $this->mysqli = $this->connect_mysql_if_closed();
        if (!$result = $this->mysqli->query("SELECT id, command, args FROM pending_jobs WHERE processing = 0 $sql_and LIMIT 1")) {
            die("no jobs available");
        }
        $job = $result->fetch_assoc();

        $this->lock_job($job['id']);
        return $job;
    }

    /* connect or reconnect to the mysql database, return the mysqli object*/
    public function connect_mysql_if_closed()
    {

        if (!$this->mysqli || !@$this->mysqli->ping()) {
            if (!$this->mysqli = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT)) {
                die("error connecting to mysql server");
            }
        }
        return $this->mysqli;
    }


    /*
    set the "processing" column to 1
    for the row that matches the current job's id
    to prevent the job from being processed twice
     */
    public function lock_job($id)
    {
        $this->mysqli = $this->connect_mysql_if_closed();
        if (!$result = $this->mysqli->query("UPDATE pending_jobs SET processing = 1 WHERE id = $id")) {
            die(__METHOD__ . " failed");
        }
        return true;
    }


    /*
    set the "processing" column to 0
    for the row that matches the current job's id
    to allow the job to be re-processed.
    this function is invoked if the command terminates in an error,
    or if processing otherwise fails.
     */
    public function unlock_job($id)
    {

        $this->mysqli = $this->connect_mysql_if_closed();
        if (!$result = $this->mysqli->query("UPDATE pending_jobs SET processing = 0 WHERE id = $id")) {
            die(__METHOD__ . " failed");
        }
        return true;
    }


    /*
    delete the job from the jobs table.
    this function is invoked when the job has finished executing and processing/saving has completed.
    */
    public function delete_job($id)
    {

        $this->mysqli = $this->connect_mysql_if_closed();
        if (!$result = $this->mysqli->query("DELETE FROM pending_jobs WHERE id = $id")) {
            die(__METHOD__ . " failed");
        }
        return true;
    }

    /* save the job command, arguments, output, and some additional metadata to a database table */
    public function save_db_completed($job)
    {
        $sql = 'INSERT INTO completed_jobs (command,args,output,time_completed,job_id) VALUES ';
        $sql .= '("' . $job['command'] . '","' . $job['args'] . '","' . $job['output'];
        $sql .= '","' . $job['time_completed'] . '",' . $job['job_id'] . ')';


        $this->mysqli = $this->connect_mysql_if_closed();

        if (!$result = $this->mysqli->query($sql)) {
            die(__METHOD__ . " failed");
        }

    }

    /* store the completed job as a CSV row.
    JSON would have been more readable, but CSV is smaller. */

    public function save_flat_completed($completed_job)
    {
        $write_str = implode(',', $completed_job);
        //use LOCK_EX mutex to prevent attempts to simultaneously write to this file.
        file_put_contents(FLAT_FILE, $write_str, FILE_APPEND | LOCK_EX);

    }

    /* save the job command, arguments, output, and some additional metadata to a redis cache.abstract
       set cache to expire in 10 seconds.
    */
    public function cache_completed_job($job)
    {

        $this->redis = $this->connect_redis_if_closed();
        $key = $job['job_id'];
        $this->redis->hmset($key, $job); //cache the job in redis
        $this->redis->expire($key, 10); //expire the key in 10 seconds
    }

    /* connect or reconnect to redis, return the redis object */
    public function connect_redis_if_closed()
    {

        if (!$this->redis || !$this->redis->ping() === 'PONG') {
            $this->redis = new Redis();
            if (!$this->redis->connect(REDIS_HOST)) {
                die("error connecting to redis server");
            } else {
                $this->redis->select(0);
            }
        }
        return $this->redis;
    }

    /* get a completed job from the completed_jobs table
    only if it is missing in (redis) caching layer.
     */
    public function get_completed_job($job_id)
    {
        $this->redis = $this->connect_redis_if_closed();

        if ($this->redis->exists($job_id)) {
            //data exists in caching layer, return it.
            $completed_job = $this->redis->hgetall($job_id);
            $completed_job['from'] = 'cache';
        } else {
            //data does not exist in caching layer.
            //get data from the mysql database and return it.
            $this->mysqli = $this->connect_mysql_if_closed();
            if (!$result = $this->mysqli->query("SELECT command,args,output,time_completed,job_id FROM completed_jobs WHERE job_id = $job_id LIMIT 1")) {
                die("unable to fetch completed job from mysql database");
            }
            $completed_job = $result->fetch_assoc();
            $completed_job['from'] = 'database';
        }
        return $completed_job;
    }
}


$jobRunner = new JobRunner();
/*
To simulate the three jobs in the queue, simply uncomment the following lines:
 */

/*
$job = get_job();
$pool->submit(new DBWork($job));
$job = get_job();
$pool->submit(new DBWork($job));
$job = get_job();
$pool->submit(new DBWork($job));
 */

/* get the job from the database */
$job = $jobRunner->get_job();
$dbwork = new DBWork($job, $jobRunner);
/* add the job to the pool */
$jobRunner->submit($dbwork);

usleep(3000000); //sleep for 3 seconds

//get the completed job with job_id = 1 from cache if available, or from mysql db if not available
$completed_job = $jobRunner->get_completed_job(1);

/*this will be from the cache*/
echo "\ncompleted_job after 3 seconds:\n";
var_dump($completed_job);
/*
output:
completed_job after 3 seconds:
array(6) {
["command"]=>
string(6) "whoami"
["args"]=>
string(0) ""
["output"]=>
string(18) "wblaptop\furlender"
["time_completed"]=>
string(19) "2015-05-31 20:44:03"
["job_id"]=>
string(1) "1"
["from"]=>
string(5) "cache"
}
 */
usleep(10000000); //sleep for 10 seconds

$completed_job = $jobRunner->get_completed_job(1);

/*this will be from the database,
because the cache will have expired about 3 seconds earlier */
echo "\n\ncompleted_job after 10 seconds:\n";
var_dump($completed_job);

/*
output:
completed_job after 3 seconds:
array(6) {
["command"]=>
string(6) "whoami"
["args"]=>
string(0) ""
["output"]=>
string(18) "wblaptop\furlender"
["time_completed"]=>
string(19) "2015-05-31 20:44:03"
["job_id"]=>
string(1) "1"
["from"]=>
string(5) "database"
}



/*
Allows the Pool to collect references determined to be garbage by the given collector.
In this case a task is deemed garbage if it has completed. This should probably be called on a timer,
or invoked from delete_job().
 */

$jobRunner->pool->collect(function (DBWork $task) {
    return $task->isComplete();
});

?>