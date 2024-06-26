SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE `vote` (
  `id` int(11) NOT NULL,
  `appKey` blob NOT NULL,
  `appSignature` blob NOT NULL,
  `referendum` blob NOT NULL,
  `number` int(11) NOT NULL,
  `locality` bigint(20) NOT NULL,
  `ballot` binary(32) NOT NULL,
  `answer` text NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `referendum` (
  `signature` blob NOT NULL,
  `deadline` datetime NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `referendum`
  ADD UNIQUE KEY `signature` (`signature`) USING HASH;

ALTER TABLE `vote`
  ADD PRIMARY KEY (`id`);
  ADD UNIQUE KEY `referendum` (`referendum`,`ballot`) USING HASH;

ALTER TABLE `vote`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

COMMIT;
