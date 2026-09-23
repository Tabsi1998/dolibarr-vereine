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

-- 0.7.0: a membership application has its own way: received, in review, accepted, rejected, withdrawn (#72).
ALTER TABLE llx_vereine_application ADD COLUMN status VARCHAR(16) DEFAULT 'received' NOT NULL AFTER fk_user;
ALTER TABLE llx_vereine_application ADD COLUMN fingerprint VARCHAR(64) AFTER status;
ALTER TABLE llx_vereine_application ADD COLUMN decided_on DATETIME AFTER fingerprint;
ALTER TABLE llx_vereine_application ADD COLUMN fk_user_decided INTEGER AFTER decided_on;
ALTER TABLE llx_vereine_application ADD COLUMN reason TEXT AFTER fk_user_decided;
ALTER TABLE llx_vereine_application ADD COLUMN note TEXT AFTER reason;
