SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE `participation` (
  `id` int(11) NOT NULL,
  `referendumFingerprint` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `referendum` varchar(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `publicKey` varchar(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `privateKey` varchar(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `published` bigint(15) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

ALTER TABLE `participation`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `referendumFingerprint` (`referendumFingerprint`),
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;
