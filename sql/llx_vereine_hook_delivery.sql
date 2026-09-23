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

-- One notice on its way to one target (#155). The job is kept, not the message: the body is built
-- again from these fields at every attempt, so nothing of the change itself is stored twice.
CREATE TABLE llx_vereine_hook_delivery(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_target INTEGER NOT NULL,
	event_id VARCHAR(64) NOT NULL,
	object_type VARCHAR(32) NOT NULL,
	object_id INTEGER NOT NULL,
	revision INTEGER DEFAULT 1 NOT NULL,
	change_kind VARCHAR(16) NOT NULL,
	occurred_at DATETIME NOT NULL,
	status VARCHAR(16) DEFAULT 'pending' NOT NULL,
	attempts INTEGER DEFAULT 0 NOT NULL,
	next_try DATETIME,
	last_code INTEGER DEFAULT 0 NOT NULL,
	last_error VARCHAR(255) DEFAULT '' NOT NULL,
	sent_at DATETIME,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
