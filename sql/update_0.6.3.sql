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

-- 0.6.3: how a consent was given, so the association can show it (Art. 7 (1) GDPR) (#109).
ALTER TABLE llx_vereine_consent ADD COLUMN proof_at DATETIME AFTER note;
ALTER TABLE llx_vereine_consent ADD COLUMN proof_form VARCHAR(128) AFTER proof_at;
ALTER TABLE llx_vereine_consent ADD COLUMN proof_ref VARCHAR(64) AFTER proof_form;
ALTER TABLE llx_vereine_consent ADD COLUMN scan_name VARCHAR(255) AFTER proof_ref;
