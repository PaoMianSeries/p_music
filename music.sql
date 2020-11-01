SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for songs
-- ----------------------------
DROP TABLE IF EXISTS `songs`;
CREATE TABLE `songs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `m_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '网易ID',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '歌名',
  `album` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '专辑名称',
  `artist` json DEFAULT NULL COMMENT '艺术家',
  `baidu` json DEFAULT NULL,
  `xiami` json DEFAULT NULL,
  `kuwo` json DEFAULT NULL,
  `kugou` json DEFAULT NULL,
  `migu` json DEFAULT NULL,
  `qq` json DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `m_id` (`m_id`) USING BTREE
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
