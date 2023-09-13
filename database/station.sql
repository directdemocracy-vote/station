SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE `participation` (
  `id` int(11) NOT NULL,
  `referendum` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `referendumFingerprint` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `publicKey` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `privateKey` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL
)

ALTER TABLE `participation`
  ADD PRIMARY KEY (`id`);
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
