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

-- What became of the excess of an invoice paid over its total (#54): Dolibarr's credit, a various payment
-- back, or a donation. One row per invoice: the excess is assigned once.
CREATE TABLE llx_vereine_overpayment(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_facture INTEGER NOT NULL,
	kind VARCHAR(16) NOT NULL,
	amount DOUBLE(24,8) DEFAULT 0 NOT NULL,
	fk_discount INTEGER,
	fk_payment_various INTEGER,
	fk_don INTEGER,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat INTEGER
) ENGINE=innodb;
