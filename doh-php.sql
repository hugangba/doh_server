SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------

--
-- 表的结构 `dns_records_ipv4`
--

CREATE TABLE `dns_records_ipv4` (
  `id` int NOT NULL,
  `domain` varchar(255) NOT NULL,
  `ipv4` varchar(15) NOT NULL,
  `timestamp` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- 表的结构 `dns_records_ipv6`
--

CREATE TABLE `dns_records_ipv6` (
  `id` int NOT NULL,
  `domain` varchar(255) NOT NULL,
  `ipv6` varchar(39) NOT NULL,
  `timestamp` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- 转储表的索引
--

--
-- 表的索引 `dns_records_ipv4`
--
ALTER TABLE `dns_records_ipv4`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_domain` (`domain`);

--
-- 表的索引 `dns_records_ipv6`
--
ALTER TABLE `dns_records_ipv6`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_domain` (`domain`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `dns_records_ipv4`
--
ALTER TABLE `dns_records_ipv4`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `dns_records_ipv6`
--
ALTER TABLE `dns_records_ipv6`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
COMMIT;

