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

-- A transmission of the donation report for FinanzOnline (#6) and what its protocol said.
CREATE TABLE llx_vereine_donation_report(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fiscal_year INTEGER NOT NULL,
	message_ref VARCHAR(36) NOT NULL,
	kind VARCHAR(4) NOT NULL,
	status VARCHAR(16) DEFAULT 'created' NOT NULL,
	line_count INTEGER DEFAULT 0 NOT NULL,
	protocol VARCHAR(8),
	protocol_test SMALLINT DEFAULT 0 NOT NULL,
	protocol_on DATETIME,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat INTEGER
) ENGINE=innodb;
