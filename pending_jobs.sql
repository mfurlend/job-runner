SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `pending_jobs`;
CREATE TABLE `pending_jobs` (
  `command` varchar(255) NOT NULL,
  `args` varchar(255) DEFAULT NULL,
  `processing` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8;

INSERT INTO `pending_jobs` VALUES ('whoami', null, '0', '1');
INSERT INTO `pending_jobs` VALUES ('date', '/T', '0', '2');
INSERT INTO `pending_jobs` VALUES ('ls', '-alh', '0', '3');
