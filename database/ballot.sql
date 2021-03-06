SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE `ballot` (
  `id` int(11) NOT NULL,
  `schema` varchar(256) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `signature` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `published` bigint(15) NOT NULL,
  `expires` bigint(15) NOT NULL,
  `referendum` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `citizenKey` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `citizenSignature` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


ALTER TABLE `ballot`
  ADD PRIMARY KEY (`id`);


ALTER TABLE `ballot`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;
