<?php

namespace local_ltiprovider\task;

use moodle\local\ltiprovider as ltiprovider;

class grades_sync extends \core\task\scheduled_task {
	public function get_name() {
		return get_string( 'task_grades_sync', 'local_ltiprovider' );
	}

	public function execute() {
		global $DB, $CFG;

		//requires
		require_once( $CFG->dirroot . "/local/ltiprovider/lib.php" );
		require_once( $CFG->dirroot . "/local/ltiprovider/locallib.php" );
		require_once( $CFG->dirroot . "/local/ltiprovider/ims-blti/OAuth.php" );
		require_once( $CFG->dirroot . "/local/ltiprovider/ims-blti/OAuthBody.php" );
		require_once( $CFG->libdir . '/gradelib.php' );
		require_once( $CFG->dirroot . '/grade/querylib.php' );

		//exec
		$timenow = time();
		if ( $tools = $DB->get_records_select( 'local_ltiprovider', 'disabled = ? AND sendgrades = ?',
			array( 0, 1 ) ) ) {
			foreach ( $tools as $tool ) {
				$users = $DB->get_records( 'local_ltiprovider_user', array( 'toolid' => $tool->id ) );
				$logs = local_ltiprovier_do_grades_sync( $tool, $users, $timenow );
				foreach ( $logs as $log ) {
					mtrace( $log );
				}
			}
		}


	}
}