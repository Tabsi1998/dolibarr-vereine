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

-- One day of voluntary work with its allowance (#7): who, when, what, which kind of allowance and how
-- much. What goes over a limit is kept like everything else; the rules mark it, they do not refuse it.
CREATE TABLE llx_vereine_volunteer(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_adherent INTEGER NOT NULL,
	duty_day DATE NOT NULL,
	activity VARCHAR(255) NOT NULL,
	kind VARCHAR(16) NOT NULL,
	amount DOUBLE(24,8) NOT NULL,
	hours DECIMAL(6,2),
	-- The confirmed helper shift it comes from (#23), so one shift is paid once.
	fk_shift_entry INTEGER,
	paid_on DATE,
	note TEXT,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
