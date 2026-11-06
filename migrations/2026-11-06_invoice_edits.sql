-- 2026-11-06: Activity log for invoice edits (and future audited events).
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS `ActivityLog` (
  `LogID` bigint(20) NOT NULL AUTO_INCREMENT,
  `EventType` varchar(50) NOT NULL,
  `EntityType` varchar(50) DEFAULT NULL,
  `EntityID` int(11) DEFAULT NULL,
  `EntityRef` varchar(100) DEFAULT NULL,
  `UserID` int(11) DEFAULT NULL,
  `Summary` text NOT NULL,
  `Detail` text DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`LogID`),
  KEY `idx_al_type_date` (`EventType`, `CreatedAt`),
  KEY `idx_al_entity` (`EntityType`, `EntityID`),
  KEY `idx_al_user` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `SchemaMigrations` (
  `Name` varchar(100) NOT NULL,
  `AppliedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`Name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `SchemaMigrations` (`Name`) VALUES ('invoice_edits_activity_log');
