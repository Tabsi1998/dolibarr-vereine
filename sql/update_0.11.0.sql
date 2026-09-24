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

-- 0.11.0: a shortened version names the revision it is derived from; a publication may be for one person (#239).
ALTER TABLE llx_vereine_document_file ADD COLUMN fk_parent INTEGER AFTER what;
ALTER TABLE llx_vereine_publication ADD COLUMN fk_adherent INTEGER DEFAULT 0 NOT NULL AFTER audience;
