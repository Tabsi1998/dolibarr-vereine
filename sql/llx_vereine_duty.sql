-- Copyright (C) 2026 IT-Tabelander <https://it.tabelander.co.at>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

-- The catalogue of what the association has to do again and again (#24): the duties of Austrian law
-- plus the association's own, each with the function that looks after it and how its day is found.
CREATE TABLE llx_vereine_duty(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	code VARCHAR(32) NOT NULL,
	label VARCHAR(128) NOT NULL,
	function_code VARCHAR(32) DEFAULT '' NOT NULL,
	basis VARCHAR(16) DEFAULT 'year_end' NOT NULL,
	offset_months INTEGER DEFAULT 0 NOT NULL,
	due_month INTEGER DEFAULT 0 NOT NULL,
	due_day INTEGER DEFAULT 0 NOT NULL,
	every_years INTEGER DEFAULT 1 NOT NULL,
	first_year INTEGER DEFAULT 0 NOT NULL,
	lead_days INTEGER DEFAULT 60 NOT NULL,
	source VARCHAR(64) DEFAULT '' NOT NULL,
	note TEXT,
	standard TINYINT DEFAULT 0 NOT NULL,
	position INTEGER DEFAULT 0 NOT NULL,
	active TINYINT DEFAULT 1 NOT NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
