# job-runner
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