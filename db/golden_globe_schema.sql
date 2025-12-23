--
-- Tabellenstruktur für Tabelle `golden_globe_category`
--

CREATE TABLE `golden_globe_category` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `golden_globe_nominations`
--

CREATE TABLE `golden_globe_nominations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year_film` int(11) NOT NULL,
  `year_award` int(11) NOT NULL,
  `ceremony` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `nominee` varchar(255) NOT NULL,
  `film` varchar(255) DEFAULT NULL,
  `imdb_const` varchar(32) DEFAULT NULL,
  `winner` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `year_film` (`year_film`),
  KEY `year_award` (`year_award`),
  KEY `imdb_const` (`imdb_const`),
  KEY `winner` (`winner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
