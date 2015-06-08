SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `completed_jobs`;
CREATE TABLE `completed_jobs` (
  `command` varchar(255) CHARACTER SET utf8 NOT NULL,
  `args` varchar(255) CHARACTER SET utf8 DEFAULT NULL,
  `output` text CHARACTER SET utf8,
  `time_completed` datetime NOT NULL,
  `job_id` int(10) NOT NULL,
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
