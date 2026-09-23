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

-- Where change notices are delivered (#155). One secret per target, with a second one during a
-- rotation, so a receiver can accept both for a short while. The secret is never shown again.
CREATE TABLE llx_vereine_hook_target(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	label VARCHAR(128) NOT NULL,
	url VARCHAR(255) NOT NULL,
	-- The user whose release the delivery runs under: without their right nothing is sent.
	fk_user INTEGER,
	key_id VARCHAR(16) NOT NULL,
	secret VARCHAR(128) NOT NULL,
	next_key_id VARCHAR(16) DEFAULT '' NOT NULL,
	next_secret VARCHAR(128) DEFAULT '' NOT NULL,
	rotate_until DATETIME,
	object_types VARCHAR(255) DEFAULT '' NOT NULL,
	allow_internal TINYINT DEFAULT 0 NOT NULL,
	active TINYINT DEFAULT 1 NOT NULL,
	cursor_value VARCHAR(255) DEFAULT '' NOT NULL,
	last_ok DATETIME,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
