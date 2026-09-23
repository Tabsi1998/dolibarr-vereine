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

-- Who acts through an external application (#153): the client that vouches for them, their name at
-- that client, and what that binding is worth. A member and a third party are two separate, optional
-- references: an applicant has neither yet, and a binding to an application alone is a binding too.
CREATE TABLE llx_vereine_identity(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	-- The technical client that vouches, by its Dolibarr login: the binding of one client is worth
	-- nothing at another.
	client VARCHAR(64) NOT NULL,
	-- How the person is called at that client. Never an e-mail address on its own: that is a hint.
	subject VARCHAR(128) NOT NULL,
	fk_adherent INTEGER,
	fk_soc INTEGER,
	fk_application INTEGER,
	-- How the binding came about: an invitation, an administrator, or a rule the association set up.
	proof VARCHAR(16) NOT NULL,
	proof_note VARCHAR(255) DEFAULT '' NOT NULL,
	-- What this identity may do. Empty means: read who you are, nothing else.
	capabilities VARCHAR(255) DEFAULT '' NOT NULL,
	linked_at DATETIME NOT NULL,
	revoked_at DATETIME,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
