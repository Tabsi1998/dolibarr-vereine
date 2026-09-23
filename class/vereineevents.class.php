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
 * \file    class/vereineevents.class.php
 * \ingroup vereine
 * \brief   Events of the association from templates (#23): one project of Dolibarr, its tasks, its day.
 *
 * The module keeps no second project management. An event becomes a project of Dolibarr with one task
 * per point of the checklist, so budget, documents and time stay where Dolibarr already keeps them.
 * The checklist here only adds what a project does not know: the phase, the function that looks after a
 * point and where the point comes from. Ticking a point off writes the progress back to the task of the
 * project. A template that changes later leaves events that already run untouched.
 */

require_once __DIR__.'/vereineeventrules.class.php';
require_once __DIR__.'/vereinefunctions.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Templates and events of the association.
 */
class VereineEvents
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last database error
	 */
	public $error = '';

	/**
	 * @var string[] Language keys of what was refused
	 */
	public $errors = array();

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Add the suggested templates this entity does not carry yet, with their points.
	 *
	 * @return int Number of templates added, <0 on error
	 */
	public function ensureStandard()
	{
		global $conf;

		$existing = array();
		foreach ($this->templates() as $template) {
			$existing[$template['code']] = true;
		}
		if ($this->error !== '') {
			return -1;
		}
		$added = 0;
		$now = $this->db->idate(dol_now());
		$entity = (int) $conf->entity;
		foreach (VereineEventRules::standard() as $position => $template) {
			if (isset($existing[$template['code']])) {
				continue;
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_event_template (entity, code, label, note, standard, position, active, datec)";
			$sql .= " VALUES (".$entity.", '".$this->db->escape($template['code'])."', '".$this->db->escape($template['label'])."',";
			$sql .= " '".$this->db->escape($template['note'])."', 1, ".(($position + 1) * 10).", 1, '".$now."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$templateId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_event_template');
			foreach ($template['tasks'] as $order => $task) {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_event_template_task (entity, fk_template, phase, label, function_code,";
				$sql .= " offset_days, source, position, datec) VALUES (".$entity.", ".$templateId.", '".$this->db->escape($task['phase'])."',";
				$sql .= " '".$this->db->escape($task['label'])."', '".$this->db->escape($task['function_code'])."', ".((int) $task['offset_days']).",";
				$sql .= " '".$this->db->escape($task['source'])."', ".(($order + 1) * 10).", '".$now."')";
				if (!$this->db->query($sql)) {
					$this->error = $this->db->lasterror();
					return -1;
				}
			}
			$added++;
		}
		return $added;
	}

	/**
	 * The templates of this entity in their order.
	 *
	 * @param bool $activeOnly Only templates that are switched on
	 * @return array<int,array{id:int,code:string,label:string,note:string,standard:bool,position:int,active:bool}>
	 */
	public function templates($activeOnly = false)
	{
		global $conf;

		$sql = "SELECT rowid, code, label, note, standard, position, active FROM ".MAIN_DB_PREFIX."vereine_event_template";
		$sql .= " WHERE entity = ".((int) $conf->entity).($activeOnly ? " AND active = 1" : "")." ORDER BY position, rowid";
		// The table exists only after the module was enabled with 0.8.0.
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$templates = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$templates[] = array('id' => (int) $obj->rowid, 'code' => (string) $obj->code, 'label' => (string) $obj->label,
				'note' => (string) $obj->note, 'standard' => (bool) $obj->standard, 'position' => (int) $obj->position,
				'active' => (bool) $obj->active);
		}
		$this->db->free($resql);
		return $templates;
	}

	/**
	 * The points of a template in their order, the phases one after the other.
	 *
	 * @param int $templateId Template
	 * @return array<int,array{id:int,phase:string,label:string,function_code:string,offset_days:int,source:string,note:string,position:int}>
	 */
	public function templateTasks($templateId)
	{
		global $conf;

		$sql = "SELECT rowid, phase, label, function_code, offset_days, source, note, position FROM ".MAIN_DB_PREFIX."vereine_event_template_task";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_template = ".((int) $templateId)." ORDER BY offset_days, position, rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$tasks = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$tasks[] = array('id' => (int) $obj->rowid, 'phase' => (string) $obj->phase, 'label' => (string) $obj->label,
				'function_code' => (string) $obj->function_code, 'offset_days' => (int) $obj->offset_days,
				'source' => (string) $obj->source, 'note' => (string) $obj->note, 'position' => (int) $obj->position);
		}
		$this->db->free($resql);
		return $tasks;
	}

	/**
	 * Store a template, a new one when there is no id.
	 *
	 * @param int                 $id   Template, 0 for a new one
	 * @param array<string,mixed> $data Keys code, label, note, active
	 * @param User                $user Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function saveTemplate($id, array $data, $user)
	{
		global $conf;

		$this->errors = VereineEventRules::validateTemplate($data);
		if ($this->errors) {
			return 0;
		}
		$entity = (int) $conf->entity;
		$label = "'".$this->db->escape(trim((string) $data['label']))."'";
		$note = (string) (isset($data['note']) ? $data['note'] : '');
		$noteValue = $note !== '' ? "'".$this->db->escape($note)."'" : "NULL";
		$active = !empty($data['active']) ? 1 : 0;
		if ((int) $id > 0) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_event_template SET label = ".$label.", note = ".$noteValue.", active = ".$active;
			$sql .= ", fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $id)." AND entity = ".$entity;
		} else {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_event_template (entity, code, label, note, standard, position, active, datec)";
			$sql .= " VALUES (".$entity.", '".$this->db->escape(trim((string) $data['code']))."', ".$label.", ".$noteValue.", 0, ";
			$sql .= $this->nextPosition('vereine_event_template', '').", ".$active.", '".$this->db->idate(dol_now())."')";
		}
		if (!$this->db->query($sql)) {
			if ($this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				$this->errors[] = 'VereineEventErrorCodeTaken';
				return 0;
			}
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::EVENT_TEMPLATE, 0, 0, trim((string) $data['label']));
		return 1;
	}

	/**
	 * Store a point of a template, a new one when there is no id.
	 *
	 * @param int                 $id         Point, 0 for a new one
	 * @param int                 $templateId Template
	 * @param array<string,mixed> $data       Keys phase, label, function_code, offset_days, source, note
	 * @param User                $user       Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function saveTemplateTask($id, $templateId, array $data, $user)
	{
		global $conf;

		$this->errors = VereineEventRules::validateTask($data);
		if ($this->errors) {
			return 0;
		}
		$entity = (int) $conf->entity;
		$values = array(
			'phase' => "'".$this->db->escape((string) $data['phase'])."'",
			'label' => "'".$this->db->escape(trim((string) $data['label']))."'",
			'function_code' => "'".$this->db->escape(trim((string) (isset($data['function_code']) ? $data['function_code'] : '')))."'",
			'offset_days' => (string) (int) $data['offset_days'],
			'source' => "'".$this->db->escape(trim((string) (isset($data['source']) ? $data['source'] : '')))."'",
			'fk_user_modif' => (string) (int) $user->id,
		);
		if ((int) $id > 0) {
			$set = array();
			foreach ($values as $column => $value) {
				$set[] = $column.' = '.$value;
			}
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_event_template_task SET ".implode(', ', $set);
			$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".$entity;
		} else {
			$values['entity'] = (string) $entity;
			$values['fk_template'] = (string) (int) $templateId;
			$values['position'] = (string) $this->nextPosition('vereine_event_template_task', ' AND fk_template = '.((int) $templateId));
			$values['datec'] = "'".$this->db->idate(dol_now())."'";
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_event_template_task (".implode(', ', array_keys($values)).")";
			$sql .= " VALUES (".implode(', ', array_values($values)).")";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::EVENT_TEMPLATE, 0, 0, trim((string) $data['label']));
		return 1;
	}

	/**
	 * Remove a point of a template. Events that already run keep theirs.
	 *
	 * @param int  $id   Point
	 * @param User $user Who removes
	 * @return int 1 when removed, -1 on error
	 */
	public function removeTemplateTask($id, $user)
	{
		global $conf;

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."vereine_event_template_task WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::EVENT_TEMPLATE, 0, 0, 'task '.((int) $id).' removed');
		return 1;
	}

	/**
	 * The next free place in a table of this entity.
	 *
	 * @param string $table Table without prefix
	 * @param string $where Further condition, already safe
	 * @return int
	 */
	private function nextPosition($table, $where)
	{
		global $conf;

		$resql = $this->db->query("SELECT MAX(position) as last FROM ".MAIN_DB_PREFIX.$table." WHERE entity = ".((int) $conf->entity).$where);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return ($obj ? (int) $obj->last : 0) + 10;
	}

	/**
	 * Make an event from a template: the event itself, a project of Dolibarr with one task per point,
	 * and the day in the agenda. Running it again for the same event adds only points that are missing,
	 * so nothing is created twice.
	 *
	 * @param int                 $templateId Template, 0 for an event without one
	 * @param array<string,mixed> $data       Keys label, event_day, end_day, place, public, registration, external_ref, note
	 * @param string              $today      Today, YYYY-MM-DD
	 * @param User                $user       Who creates
	 * @return int Event, 0 when refused (see errors), -1 on error
	 */
	public function createFromTemplate($templateId, array $data, $today, $user)
	{
		global $conf;

		$this->errors = VereineEventRules::validateEvent($data);
		if ($this->errors) {
			return 0;
		}
		$entity = (int) $conf->entity;
		$day = trim((string) $data['event_day']);
		$end = trim((string) (isset($data['end_day']) ? $data['end_day'] : ''));
		$registration = isset($data['registration']) ? (string) $data['registration'] : VereineEventRules::REGISTRATION_NONE;
		$public = !empty($data['public']) ? 1 : 0;
		$note = (string) (isset($data['note']) ? $data['note'] : '');

		$this->db->begin();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_event (entity, fk_template, label, event_day, end_day, place, public, registration,";
		$sql .= " external_ref, status, note, datec, fk_user_modif) VALUES (".$entity.", ".((int) $templateId > 0 ? (int) $templateId : "NULL").",";
		$sql .= " '".$this->db->escape(trim((string) $data['label']))."', '".$this->db->escape($day)."', ";
		$sql .= ($end !== '' ? "'".$this->db->escape($end)."'" : "NULL").", '".$this->db->escape(trim((string) (isset($data['place']) ? $data['place'] : '')))."',";
		$sql .= " ".$public.", '".$this->db->escape($registration)."', '".$this->db->escape(trim((string) (isset($data['external_ref']) ? $data['external_ref'] : '')))."',";
		$sql .= " '".VereineEventRules::STATUS_PLANNED."', ".($note !== '' ? "'".$this->db->escape($note)."'" : "NULL").",";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$eventId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_event');
		$this->db->commit();

		$projectId = $this->addProject($eventId, $data, $day, $end, $registration, $user);
		$agendaId = $this->addAgenda($data, $day, $user);
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_event SET fk_projet = ".($projectId > 0 ? $projectId : "NULL");
		$sql .= ", fk_actioncomm = ".($agendaId > 0 ? $agendaId : "NULL")." WHERE rowid = ".$eventId." AND entity = ".$entity;
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($this->fillChecklist($eventId, (int) $templateId, $day, $projectId, $today, $user) < 0) {
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::EVENT_CREATED, 0, 0, trim((string) $data['label']).' ('.$day.')');
		return $eventId;
	}

	/**
	 * Write the points of the template that the event does not carry yet, each as a task of the project.
	 *
	 * @param int    $eventId    Event
	 * @param int    $templateId Template, 0 for none
	 * @param string $day        Day of the event
	 * @param int    $projectId  Project of Dolibarr, 0 when the project module is off
	 * @param string $today      Today
	 * @param User   $user       Who creates
	 * @return int Number written, <0 on error
	 */
	public function fillChecklist($eventId, $templateId, $day, $projectId, $today, $user)
	{
		global $conf;

		if ((int) $templateId < 1) {
			return 0;
		}
		$entity = (int) $conf->entity;
		$taken = array();
		foreach ($this->checklist($eventId, $today) as $row) {
			if ($row['template_task_id'] > 0) {
				$taken[$row['template_task_id']] = true;
			}
		}
		$written = 0;
		$now = $this->db->idate(dol_now());
		foreach ($this->templateTasks($templateId) as $order => $task) {
			if (isset($taken[$task['id']])) {
				continue;
			}
			$due = VereineEventRules::due($day, $task['offset_days']);
			$taskId = $this->addProjectTask($projectId, $task, $due, $user);
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_event_task (entity, fk_event, fk_template_task, phase, label, function_code,";
			$sql .= " due_on, source, fk_task, position, datec, fk_user_modif) VALUES (".$entity.", ".((int) $eventId).", ".((int) $task['id']).",";
			$sql .= " '".$this->db->escape($task['phase'])."', '".$this->db->escape($task['label'])."', '".$this->db->escape($task['function_code'])."',";
			$sql .= " ".($due !== '' ? "'".$this->db->escape($due)."'" : "NULL").", '".$this->db->escape($task['source'])."',";
			$sql .= " ".($taskId > 0 ? $taskId : "NULL").", ".(($order + 1) * 10).", '".$now."', ".((int) $user->id).")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$written++;
		}
		return $written;
	}

	/**
	 * The project of Dolibarr that carries the event, when the project module is switched on.
	 *
	 * @param int                 $eventId      Event, for the reference
	 * @param array<string,mixed> $data         Entered data
	 * @param string              $day          First day
	 * @param string              $end          Last day, empty for one day
	 * @param string              $registration Who leads the sign-up
	 * @param User                $user         Who creates
	 * @return int Project, 0 when there is none
	 */
	private function addProject($eventId, array $data, $day, $end, $registration, $user)
	{
		if (!isModEnabled('project')) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';

		$project = new Project($this->db);
		$project->ref = 'VER'.str_replace('-', '', $day).'-'.((int) $eventId);
		$project->title = dol_trunc(trim((string) $data['label']), 250, 'right', 'UTF-8', 1);
		$project->description = (string) (isset($data['note']) ? $data['note'] : '');
		$project->date_start = dol_mktime(0, 0, 0, (int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4));
		$project->date_end = $end !== ''
			? dol_mktime(23, 59, 59, (int) substr($end, 5, 2), (int) substr($end, 8, 2), (int) substr($end, 0, 4))
			: $project->date_start;
		$project->statut = 1;
		$project->public = !empty($data['public']) ? 1 : 0;
		$project->usage_task = 1;
		// Dolibarr's own event organisation only when Dolibarr leads the sign-up, so nothing is booked twice (#165).
		$project->usage_organize_event = $registration === VereineEventRules::REGISTRATION_DOLIBARR ? 1 : 0;
		$projectId = (int) $project->create($user);
		if ($projectId <= 0) {
			dol_syslog(__METHOD__.' project: '.$project->error, LOG_WARNING);
			return 0;
		}
		return $projectId;
	}

	/**
	 * One task of the project for a point of the checklist.
	 *
	 * @param int                 $projectId Project, 0 when there is none
	 * @param array<string,mixed> $task      Point of the template
	 * @param string              $due       Day it is due
	 * @param User                $user      Who creates
	 * @return int Task, 0 when there is none
	 */
	private function addProjectTask($projectId, array $task, $due, $user)
	{
		if ((int) $projectId < 1 || !isModEnabled('project')) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';

		$row = new Task($this->db);
		$row->fk_project = (int) $projectId;
		$row->label = dol_trunc($task['label'], 250, 'right', 'UTF-8', 1);
		$row->description = $task['source'] !== '' ? $task['source'] : '';
		$row->progress = 0;
		if (VereineEventRules::isDay($due)) {
			$row->date_start = dol_mktime(0, 0, 0, (int) substr($due, 5, 2), (int) substr($due, 8, 2), (int) substr($due, 0, 4));
			$row->date_end = $row->date_start;
		}
		$taskId = (int) $row->create($user);
		if ($taskId <= 0) {
			dol_syslog(__METHOD__.' task: '.$row->error, LOG_WARNING);
			return 0;
		}
		return $taskId;
	}

	/**
	 * The day of the event in Dolibarr's agenda.
	 *
	 * @param array<string,mixed> $data Entered data
	 * @param string              $day  Day
	 * @param User                $user Who creates
	 * @return int Event of the agenda, 0 when there is none
	 */
	private function addAgenda(array $data, $day, $user)
	{
		global $langs;

		if (!isModEnabled('agenda')) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

		$langs->load('vereine@vereine');
		$event = new ActionComm($this->db);
		$event->type_code = 'AC_OTH';
		$event->label = dol_trunc(trim((string) $data['label']), 250, 'right', 'UTF-8', 1);
		$event->location = (string) (isset($data['place']) ? $data['place'] : '');
		$event->note_private = $langs->transnoentities('VereineEventAgendaNote');
		$event->datep = dol_mktime(0, 0, 0, (int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4));
		$event->datef = $event->datep;
		$event->fulldayevent = 1;
		$event->percentage = -1;
		$event->userownerid = (int) $user->id;
		$eventId = (int) $event->create($user);
		if ($eventId <= 0) {
			dol_syslog(__METHOD__.' agenda event: '.$event->error, LOG_WARNING);
			return 0;
		}
		return $eventId;
	}

	/**
	 * The events of this entity, the next ones first.
	 *
	 * @param string $from Day from which to look, empty for all
	 * @param int    $limit At most so many, 0 for all
	 * @return array<int,array<string,mixed>>
	 */
	public function all($from = '', $limit = 0)
	{
		global $conf;

		$sql = "SELECT rowid, fk_template, label, event_day, end_day, place, public, registration, external_ref, status, fk_projet,";
		$sql .= " fk_actioncomm, note FROM ".MAIN_DB_PREFIX."vereine_event WHERE entity = ".((int) $conf->entity);
		if (VereineEventRules::isDay($from)) {
			$sql .= " AND (COALESCE(end_day, event_day) >= '".$this->db->escape($from)."')";
		}
		$sql .= " ORDER BY event_day, rowid".((int) $limit > 0 ? " LIMIT ".((int) $limit) : "");
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$events = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$events[] = array('id' => (int) $obj->rowid, 'template_id' => (int) $obj->fk_template, 'label' => (string) $obj->label,
				'event_day' => substr((string) $obj->event_day, 0, 10), 'end_day' => $obj->end_day ? substr((string) $obj->end_day, 0, 10) : '',
				'place' => (string) $obj->place, 'public' => (bool) $obj->public, 'registration' => (string) $obj->registration,
				'external_ref' => (string) $obj->external_ref, 'status' => (string) $obj->status, 'project_id' => (int) $obj->fk_projet,
				'agenda_id' => (int) $obj->fk_actioncomm, 'note' => (string) $obj->note);
		}
		$this->db->free($resql);
		return $events;
	}

	/**
	 * One event, null when there is none.
	 *
	 * @param int $id Event
	 * @return array<string,mixed>|null
	 */
	public function fetch($id)
	{
		foreach ($this->all() as $event) {
			if ($event['id'] === (int) $id) {
				return $event;
			}
		}
		return null;
	}

	/**
	 * The checklist of an event: every point with its day, who looks after it and how it stands.
	 *
	 * @param int    $eventId Event
	 * @param string $today   Today, YYYY-MM-DD
	 * @return array<int,array<string,mixed>> In the order of the phases and the days
	 */
	public function checklist($eventId, $today)
	{
		global $conf;

		$sql = "SELECT rowid, fk_template_task, phase, label, function_code, due_on, source, fk_task, done_on, position";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_event_task WHERE entity = ".((int) $conf->entity)." AND fk_event = ".((int) $eventId);
		$sql .= " ORDER BY due_on, position, rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$due = $obj->due_on ? substr((string) $obj->due_on, 0, 10) : '';
			$done = $obj->done_on ? substr((string) $obj->done_on, 0, 10) : '';
			$rows[] = array('id' => (int) $obj->rowid, 'template_task_id' => (int) $obj->fk_template_task, 'phase' => (string) $obj->phase,
				'label' => (string) $obj->label, 'function_code' => (string) $obj->function_code, 'due_on' => $due,
				'source' => (string) $obj->source, 'task_id' => (int) $obj->fk_task, 'done_on' => $done,
				'state' => VereineEventRules::state($due, $today, $done !== ''));
		}
		$this->db->free($resql);
		$order = array_flip(VereineEventRules::PHASES);
		usort($rows, function ($left, $right) use ($order) {
			if ($order[$left['phase']] !== $order[$right['phase']]) {
				return $order[$left['phase']] - $order[$right['phase']];
			}
			return strcmp($left['due_on'] !== '' ? $left['due_on'] : '9999', $right['due_on'] !== '' ? $right['due_on'] : '9999');
		});
		return $rows;
	}

	/**
	 * Add a point the association thought of itself.
	 *
	 * @param int                 $eventId Event
	 * @param array<string,mixed> $data    Keys phase, label, function_code, due_on, source
	 * @param User                $user    Who adds
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function addTask($eventId, array $data, $user)
	{
		global $conf;

		$event = $this->fetch($eventId);
		if ($event === null) {
			$this->errors[] = 'VereineEventErrorUnknown';
			return 0;
		}
		$due = trim((string) (isset($data['due_on']) ? $data['due_on'] : ''));
		$check = $data;
		// An own point carries its day, not a number of days; the rules check the day instead.
		$check['offset_days'] = '0';
		$this->errors = VereineEventRules::validateTask($check);
		if ($due !== '' && !VereineEventRules::isDay($due)) {
			$this->errors[] = 'VereineEventErrorDay';
		}
		if ($this->errors) {
			return 0;
		}
		$task = array('label' => trim((string) $data['label']), 'source' => trim((string) (isset($data['source']) ? $data['source'] : '')));
		$taskId = $this->addProjectTask((int) $event['project_id'], $task, $due, $user);
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_event_task (entity, fk_event, fk_template_task, phase, label, function_code,";
		$sql .= " due_on, source, fk_task, position, datec, fk_user_modif) VALUES (".((int) $conf->entity).", ".((int) $eventId).", NULL,";
		$sql .= " '".$this->db->escape((string) $data['phase'])."', '".$this->db->escape($task['label'])."',";
		$sql .= " '".$this->db->escape(trim((string) (isset($data['function_code']) ? $data['function_code'] : '')))."',";
		$sql .= " ".($due !== '' ? "'".$this->db->escape($due)."'" : "NULL").", '".$this->db->escape($task['source'])."',";
		$sql .= " ".($taskId > 0 ? $taskId : "NULL").", ".$this->nextPosition('vereine_event_task', ' AND fk_event = '.((int) $eventId)).",";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::EVENT_TASK, 0, 0, $event['label'].': '.$task['label']);
		return 1;
	}

	/**
	 * Tick a point off, or take it back. The task of the project follows.
	 *
	 * @param int    $taskId Point of the checklist
	 * @param string $day    Day it was done, empty to take it back
	 * @param User   $user   Who says so
	 * @return int 1 when stored, -1 on error
	 */
	public function markDone($taskId, $day, $user)
	{
		global $conf;

		$done = VereineEventRules::isDay($day) ? (string) $day : '';
		$entity = (int) $conf->entity;
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_event_task SET done_on = ".($done !== '' ? "'".$this->db->escape($done)."'" : "NULL");
		$sql .= ", fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $taskId)." AND entity = ".$entity;
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$resql = $this->db->query("SELECT label, fk_task FROM ".MAIN_DB_PREFIX."vereine_event_task WHERE rowid = ".((int) $taskId)
			." AND entity = ".$entity);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if ($obj && (int) $obj->fk_task > 0 && isModEnabled('project')) {
			require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';

			$row = new Task($this->db);
			if ($row->fetch((int) $obj->fk_task) > 0) {
				$row->progress = $done !== '' ? 100 : 0;
				if ($row->update($user) < 0) {
					dol_syslog(__METHOD__.' task: '.$row->error, LOG_WARNING);
				}
			}
		}
		VereineLog::add($this->db, $user, VereineLog::EVENT_TASK_DONE, 0, 0, ($obj ? (string) $obj->label : '').($done !== '' ? ': '.$done : ''));
		return 1;
	}

	/**
	 * Say that an event took place or was called off.
	 *
	 * @param int    $id     Event
	 * @param string $status One of VereineEventRules::STATUSES
	 * @param User   $user   Who says so
	 * @return int 1 when stored, 0 when refused, -1 on error
	 */
	public function setStatus($id, $status, $user)
	{
		global $conf;

		if (!in_array((string) $status, VereineEventRules::STATUSES, true)) {
			$this->errors[] = 'VereineEventErrorStatus';
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_event SET status = '".$this->db->escape((string) $status)."'";
		$sql .= ", fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::EVENT_STATUS, 0, 0, ((int) $id).': '.$status);
		return 1;
	}

	/**
	 * The events that are still to come, with how far they have got, for the overview.
	 *
	 * @param string $today Today, YYYY-MM-DD
	 * @param int    $limit At most so many
	 * @return array<int,array<string,mixed>> Keys id, label, event_day, place, progress
	 */
	public function upcoming($today, $limit = 5)
	{
		$rows = array();
		foreach ($this->all($today, (int) $limit) as $event) {
			if ($event['status'] === VereineEventRules::STATUS_CANCELLED) {
				continue;
			}
			$rows[] = $event + array('progress' => VereineEventRules::progress($this->checklist($event['id'], $today), $today));
		}
		return $rows;
	}

	/**
	 * Whether someone may plan events and tick points off: administrators and whoever holds a function.
	 *
	 * @param User   $user  Who looks
	 * @param string $today Today, YYYY-MM-DD
	 * @return bool
	 */
	public function mayManage($user, $today)
	{
		if (!empty($user->admin)) {
			return true;
		}
		if ((int) $user->fk_member < 1) {
			return false;
		}
		$functions = new VereineFunctions($this->db);
		foreach ($functions->holdersByCode($today) as $holders) {
			foreach ($holders as $holder) {
				if ((int) $holder['member_id'] === (int) $user->fk_member) {
					return true;
				}
			}
		}
		return false;
	}
}
