<?php
require_once('job_runner.php');


$jobRunner = new JobRunner();
/*
To simulate the three jobs in the queue, simply uncomment the following lines:
 */

/*
$job = get_job();
$pool->submit(new DBWork($job, $jobRunner));
$job = get_job();
$pool->submit(new DBWork($job, $jobRunner));
$job = get_job();
$pool->submit(new DBWork($job, $jobRunner));
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