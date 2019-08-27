<?php
namespace local_ltiprovider\task;

use moodle\local\ltiprovider as ltiprovider;

class grades_sync extends \core\task\scheduled_task {      
    public function get_name() {
		return get_string('task_grades_sync', 'local_ltiprovider');
    }
                                                                     
    public function execute() {
		global $DB, $CFG;
		
		//requires
		require_once($CFG->dirroot."/local/ltiprovider/lib.php");
		require_once($CFG->dirroot."/local/ltiprovider/locallib.php");
		require_once($CFG->dirroot."/local/ltiprovider/ims-blti/OAuth.php");
		require_once($CFG->dirroot."/local/ltiprovider/ims-blti/OAuthBody.php");
		require_once($CFG->libdir.'/gradelib.php');
		require_once($CFG->dirroot.'/grade/querylib.php');
		
		//exec
		$timenow = time();
		if ($tools = $DB->get_records_select('local_ltiprovider', 'disabled = ? AND sendgrades = ?', array(0, 1))) {
			foreach ($tools as $tool) {
				//if ($tool->lastsync + $synctime < $timenow) {
					mtrace(" Starting sync tool for grades id $tool->id course id $tool->courseid");
					if ($tool->requirecompletion) {
						mtrace("  Grades require activity or course completion");
					}
					$user_count = 0;
					$send_count = 0;
					$error_count = 0;
	
					$completion = new \completion_info(get_course($tool->courseid));
	
					$do_update_last_sync = false;
	
					if ($users = $DB->get_records('local_ltiprovider_user', array('toolid' => $tool->id))) {
						foreach ($users as $user) {
	
							$data = array(
								'tool' => $tool,
								'user' => $user,
							);
							local_ltiprovider_call_hook('grades', (object) $data);
	
							$user_count = $user_count + 1;
							// This can happen is the sync process has an unexpected error
							if ( strlen($user->serviceurl) < 1 ) {
								mtrace("   Empty serviceurl");
								continue;
							}
							if ( strlen($user->sourceid) < 1 ) {
								mtrace("   Empty sourceid");
								continue;
							}
	
							if ($user->lastsync > $tool->lastsync) {
								mtrace("   Skipping user {$user->id} due to recent sync");
								continue;
							}
	
							$grade = false;
							if ($context = $DB->get_record('context', array('id' => $tool->contextid))) {
								if ($context->contextlevel == CONTEXT_COURSE) {
	
									if ($tool->requirecompletion and !$completion->is_course_complete($user->userid)) {
										mtrace("   Skipping user $user->userid since he didn't complete the course");
										continue;
									}
	
									if ($tool->sendcompletion) {
										$grade = $completion->is_course_complete($user->userid) ? 1 : 0;
										$grademax = 1;
									} else if ($grade = grade_get_course_grade($user->userid, $tool->courseid)) {
										$grademax = floatval($grade->item->grademax);
										$grade = $grade->grade;
									}
								} else if ($context->contextlevel == CONTEXT_MODULE) {
									$cm = get_coursemodule_from_id(false, $context->instanceid, 0, false, MUST_EXIST);
	
									if ($tool->requirecompletion) {
										$data = $completion->get_data($cm, false, $user->userid);
										if ($data->completionstate != COMPLETION_COMPLETE_PASS and $data->completionstate != COMPLETION_COMPLETE) {
											mtrace("   Skipping user $user->userid since he didn't complete the activity");
											continue;
										}
									}
	
									if ($tool->sendcompletion) {
										$data = $completion->get_data($cm, false, $user->userid);
										if ($data->completionstate == COMPLETION_COMPLETE_PASS ||
												$data->completionstate == COMPLETION_COMPLETE ||
												$data->completionstate == COMPLETION_COMPLETE_FAIL) {
											$grade = 1;
										} else {
											$grade = 0;
										}
										$grademax = 1;
									} else {
										$grades = grade_get_grades($cm->course, 'mod', $cm->modname, $cm->instance, $user->userid);
										if (empty($grades->items[0]->grades)) {
											$grade = false;
										} else {
											$grade = reset($grades->items[0]->grades);
											if (!empty($grade->item)) {
												$grademax = floatval($grade->item->grademax);
											} else {
												$grademax = floatval($grades->items[0]->grademax);
											}
											$grade = $grade->grade;
										}
									}
								}
	
								if ( $grade === false || $grade === NULL || strlen($grade) < 1) {
									mtrace("   Invalid grade $grade");
									continue;
								}
	
								// No need to be dividing by zero
								if ( $grademax == 0.0 ) $grademax = 100.0;
	
								// TODO: Make lastgrade should be float or string - but it is integer so we truncate
								// TODO: Then remove those intval() calls
	
								// Don't double send
								if ( intval($grade) == $user->lastgrade ) {
									mtrace("   Skipping, last grade send is equal to current grade");
									continue;
								}
	
								// We sync with the external system only when the new grade differs with the previous one
								// TODO - Global setting for check this
								if ($grade >= 0 and $grade <= $grademax) {
									$float_grade = $grade / $grademax;
									$body = local_ltiprovider_create_service_body($user->sourceid, $float_grade);
	
									try {
										$response = ltiprovider\sendOAuthBodyPOST('POST', $user->serviceurl, $user->consumerkey, $user->consumersecret, 'application/xml', $body);
									} catch (Exception $e) {
										mtrace(" ".$e->getMessage());
										$error_count = $error_count + 1;
										continue;
									}
	
									// TODO - Check for errors in $retval in a correct way (parsing xml)
									if (strpos(strtolower($response), 'success') !== false) {
										$do_update_last_sync = true;
	
										$DB->set_field('local_ltiprovider_user', 'lastsync', $timenow, array('id' => $user->id));
										$DB->set_field('local_ltiprovider_user', 'lastgrade', intval($grade), array('id' => $user->id));
										mtrace(" User grade sent to remote system. userid: $user->userid grade: $float_grade");
										$send_count = $send_count + 1;
									} else {
										mtrace(" User grade send failed. userid: $user->userid grade: $float_grade: " . $response);
										$error_count = $error_count + 1;
									}
								} else {
									mtrace(" User grade for user $user->userid out of range: grade = ".$grade);
									$error_count = $error_count + 1;
								}
							} else {
								mtrace(" Invalid context: contextid = ".$tool->contextid);
							}
						}
					}
					mtrace(" Completed sync tool id $tool->id course id $tool->courseid users=$user_count sent=$send_count errors=$error_count");
					if ($do_update_last_sync) {
						$DB->set_field('local_ltiprovider', 'lastsync', $timenow, array('id' => $tool->id));
					}
				//}
			}
		}
		
		
    }
}