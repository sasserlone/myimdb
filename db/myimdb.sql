-- phpMyAdmin SQL Dump
-- version 5.0.4
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Erstellungszeit: 15. Dez 2025 um 17:48
-- Server-Version: 10.4.16-MariaDB
-- PHP-Version: 7.3.24

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Datenbank: `myimdb`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `episodes`
--

CREATE TABLE `episodes` (
  `id` int(11) NOT NULL,
  `tconst` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_tconst` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `season_number` smallint(6) DEFAULT NULL,
  `episode_number` smallint(6) DEFAULT NULL,
  `visible` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `episodes_new`
--

CREATE TABLE `episodes_new` (
  `id` int(11) NOT NULL,
  `tconst` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_tconst` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `season_number` smallint(6) DEFAULT NULL,
  `episode_number` smallint(6) DEFAULT NULL,
  `visible` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `genres`
--

CREATE TABLE `genres` (
  `id` int(11) NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `movies`
--

CREATE TABLE `movies` (
  `id` int(11) NOT NULL,
  `const` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `your_rating` int(11) DEFAULT NULL,
  `date_rated` date DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `imdb_rating` float DEFAULT NULL,
  `runtime_mins` int(11) DEFAULT NULL,
  `year` int(11) DEFAULT NULL,
  `genres` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `num_votes` int(11) DEFAULT NULL,
  `release_date` date DEFAULT NULL,
  `directors` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `movies_genres`
--

CREATE TABLE `movies_genres` (
  `id` int(11) NOT NULL,
  `movie_id` int(11) NOT NULL,
  `genre_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `episodes`
--
ALTER TABLE `episodes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tconst` (`tconst`),
  ADD KEY `idx_tconst` (`tconst`),
  ADD KEY `idx_parent_tconst` (`parent_tconst`);

--
-- Indizes für die Tabelle `episodes_new`
--
ALTER TABLE `episodes_new`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tconst` (`tconst`),
  ADD KEY `idx_tconst` (`tconst`),
  ADD KEY `idx_parent_tconst` (`parent_tconst`);

--
-- Indizes für die Tabelle `genres`
--
ALTER TABLE `genres`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_genre_name` (`name`);

--
-- Indizes für die Tabelle `movies`
--
ALTER TABLE `movies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `const` (`const`),
  ADD KEY `idx_const` (`const`),
  ADD KEY `idx_title` (`title`),
  ADD KEY `idx_year` (`year`);

--
-- Indizes für die Tabelle `movies_genres`
--
ALTER TABLE `movies_genres`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_movie_genre` (`movie_id`,`genre_id`),
  ADD KEY `idx_movie_id` (`movie_id`),
  ADD KEY `idx_genre_id` (`genre_id`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `episodes`
--
ALTER TABLE `episodes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `episodes_new`
--
ALTER TABLE `episodes_new`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `genres`
--
ALTER TABLE `genres`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `movies`
--
ALTER TABLE `movies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `movies_genres`
--
ALTER TABLE `movies_genres`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `movies_genres`
--
ALTER TABLE `movies_genres`
  ADD CONSTRAINT `fk_mg_genre` FOREIGN KEY (`genre_id`) REFERENCES `genres` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_mg_movie` FOREIGN KEY (`movie_id`) REFERENCES `movies` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
