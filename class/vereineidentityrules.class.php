<?php
/* Copyright (C) 2026 IT-Tabelander <https://it.tabelander.co.at>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    class/vereineidentityrules.class.php
 * \ingroup vereine
 * \brief   Rules of the verified external identities (#153): who may act for whom, plain PHP.
 *
 * Two questions are kept apart on purpose. The first is which program is calling: that is the technical
 * client with its API key, and it is a server that the association trusts. The second is which person
 * that program is acting for: that is the subject, and it is only worth something because the client
 * vouches for it and the association bound it once, on purpose.
 *
 * What the association never accepts is a program saying "this user is verified, trust me" without such
 * a binding. An e-mail address and a member number are hints for finding a candidate, never proof: two
 * people in one family share an address, and a member number can be guessed.
 *
 * A binding is worth exactly what it was made for. It belongs to one client, one entity and one object,
 * and it carries the abilities the association switched on for it. Everything is off until somebody
 * switches it on.
 */

/**
 * Rules of the external identities.
 */
class VereineIdentityRules
{
	/** Read and change one's own consents. */
	const CAPABILITY_CONSENTS = 'consents';
	/** Follow one's own application for membership. */
	const CAPABILITY_APPLICATIONS = 'applications';
	/** Fetch one's own documents. */
	const CAPABILITY_DOCUMENTS = 'documents';
	/** Cast one's own vote. */
	const CAPABILITY_VOTES = 'votes';

	/** Every ability that can be switched on. Nothing is on until somebody switches it on. */
	const CAPABILITIES = array('consents', 'applications', 'documents', 'votes');

	/** The binding came from a one-time invitation the person used. */
	const PROOF_INVITATION = 'invitation';
	/** An administrator of the association made it after checking. */
	const PROOF_ADMIN = 'admin';
	/** A rule the association set up on purpose made it. */
	const PROOF_RULE = 'rule';

	/** How a binding may come about. */
	const PROOFS = array('invitation', 'admin', 'rule');

	/** How long an invitation is good for, in seconds. */
	const INVITE_SECONDS = 3600;

	/** How long a code is. */
	const CODE_BYTES = 24;

	/**
	 * What is wrong with the name of a person at a client.
	 *
	 * @param string $subject The name
	 * @return string Empty when fine, otherwise a language key
	 */
	public static function checkSubject($subject)
	{
		$text = trim((string) $subject);
		if ($text === '' || mb_strlen($text, 'UTF-8') > 128) {
			return 'VereineIdentityErrorSubject';
		}
		// A name that looks like an e-mail address invites treating an address as proof; it is not.
		if (!preg_match('/^[A-Za-z0-9._:@|-]{3,128}$/', $text)) {
			return 'VereineIdentityErrorSubject';
		}
		return '';
	}

	/**
	 * The abilities of a text, only the ones that exist, without repetition.
	 *
	 * @param mixed $capabilities Comma separated, or a list
	 * @return string[]
	 */
	public static function capabilities($capabilities)
	{
		$wanted = is_array($capabilities) ? $capabilities : explode(',', (string) $capabilities);
		$clean = array();
		foreach ($wanted as $capability) {
			$capability = trim((string) $capability);
			if ($capability !== '' && in_array($capability, self::CAPABILITIES, true)) {
				$clean[] = $capability;
			}
		}
		return array_values(array_unique($clean));
	}

	/**
	 * Whether a binding is alive: it exists, it was not revoked, and it belongs to this client and entity.
	 *
	 * @param array<string,mixed>|null $identity The binding, null when there is none
	 * @param string                   $client   The client that is calling
	 * @param int                      $entity   The entity that is being read
	 * @return string Empty when alive, otherwise a language key
	 */
	public static function alive($identity, $client, $entity)
	{
		if ($identity === null) {
			return 'VereineIdentityErrorUnknown';
		}
		if ((string) $identity['client'] !== (string) $client) {
			return 'VereineIdentityErrorClient';
		}
		if ((int) $identity['entity'] !== (int) $entity) {
			return 'VereineIdentityErrorEntity';
		}
		if ((string) $identity['revoked_at'] !== '') {
			return 'VereineIdentityErrorRevoked';
		}
		return '';
	}

	/**
	 * Whether a binding may do something to an object.
	 *
	 * The object has to be the very one the binding is for. A binding to an application is not a
	 * membership: an applicant reads their application and nothing else, and being accepted later is
	 * what makes them a member, not this.
	 *
	 * @param array<string,mixed>|null $identity   The binding
	 * @param string                   $client     The client that is calling
	 * @param int                      $entity     The entity
	 * @param string                   $capability What it wants to do
	 * @param string                   $objectType What it wants to do it to: member or application
	 * @param int                      $objectId   Which one, 0 for the one of the binding
	 * @return string Empty when allowed, otherwise a language key
	 */
	public static function decide($identity, $client, $entity, $capability, $objectType, $objectId = 0)
	{
		$wrong = self::alive($identity, $client, $entity);
		if ($wrong !== '') {
			return $wrong;
		}
		if (!in_array((string) $capability, self::CAPABILITIES, true)) {
			return 'VereineIdentityErrorCapability';
		}
		if (!in_array((string) $capability, self::capabilities($identity['capabilities']), true)) {
			return 'VereineIdentityErrorNotAllowed';
		}
		$own = array('member' => (int) $identity['member_id'], 'application' => (int) $identity['application_id']);
		if (!isset($own[(string) $objectType])) {
			return 'VereineIdentityErrorObject';
		}
		if ($own[(string) $objectType] < 1) {
			return 'VereineIdentityErrorNoBinding';
		}
		// Asking for somebody else's object is not a mistake to be forgiven; it is a refusal.
		if ((int) $objectId > 0 && (int) $objectId !== $own[(string) $objectType]) {
			return 'VereineIdentityErrorForeign';
		}
		return '';
	}

	/**
	 * A code for an invitation, and what is kept of it.
	 *
	 * Only the hash is stored: whoever reads the table later still cannot use the invitation.
	 *
	 * @return array{code:string,hash:string}
	 */
	public static function newCode()
	{
		$code = rtrim(strtr(base64_encode(random_bytes(self::CODE_BYTES)), '+/', '-_'), '=');
		return array('code' => $code, 'hash' => self::hash($code));
	}

	/**
	 * What is kept of a code.
	 *
	 * @param string $code The code
	 * @return string
	 */
	public static function hash($code)
	{
		return hash('sha256', (string) $code);
	}

	/**
	 * Whether an invitation can still be used.
	 *
	 * @param array<string,mixed>|null $invite The invitation
	 * @param string                   $client The client that is calling
	 * @param string                   $now    Now, YYYY-MM-DD HH:MM:SS
	 * @return string Empty when it can, otherwise a language key
	 */
	public static function inviteUsable($invite, $client, $now)
	{
		if ($invite === null) {
			return 'VereineIdentityErrorCode';
		}
		if ((string) $invite['client'] !== (string) $client) {
			// An invitation for one application is worth nothing at another.
			return 'VereineIdentityErrorClient';
		}
		if ((string) $invite['used_at'] !== '') {
			return 'VereineIdentityErrorUsed';
		}
		if ((string) $invite['expires_at'] < (string) $now) {
			return 'VereineIdentityErrorExpired';
		}
		return '';
	}

	/**
	 * What a caller is told about itself: who it is bound to and what it may do, never more.
	 *
	 * @param array<string,mixed> $identity The binding
	 * @return array<string,mixed>
	 */
	public static function describe(array $identity)
	{
		return array(
			'subject' => (string) $identity['subject'],
			'member_id' => (int) $identity['member_id'] > 0 ? (int) $identity['member_id'] : null,
			'application_id' => (int) $identity['application_id'] > 0 ? (int) $identity['application_id'] : null,
			'capabilities' => self::capabilities($identity['capabilities']),
			'proof' => (string) $identity['proof'],
			'linked_at' => str_replace(' ', 'T', substr((string) $identity['linked_at'], 0, 19)).'Z',
		);
	}
}
