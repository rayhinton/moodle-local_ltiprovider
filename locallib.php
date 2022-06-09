<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * General plugin functions.
 *
 * @package    local
 * @subpackage ltiprovider
 * @copyright  2011 Juan Leyva <juanleyvadelgado@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'MOODLE_INTERNAL' ) or die;
require_once( $CFG->dirroot . '/local/ltiprovider/ims-blti/blti_util.php' );
require_once( $CFG->dirroot . '/course/lib.php' );
require_once( $CFG->dirroot . '/local/ltiprovider/ims-blti/OAuthBody.php' );

use moodle\local\ltiprovider as ltiprovider;

/**
 * Create a IMS POX body request for sync grades.
 *
 * @param string $source Sourceid required for the request
 * @param float $grade User final grade
 *
 * @return string
 */
function local_ltiprovider_create_service_body( $source, $grade ) {
    return '<?xml version = "1.0" encoding = "UTF-8"?>
<imsx_POXEnvelopeRequest xmlns = "http://www.imsglobal.org/services/ltiv1p1/xsd/imsoms_v1p0">
  <imsx_POXHeader>
    <imsx_POXRequestHeaderInfo>
      <imsx_version>V1.0</imsx_version>
      <imsx_messageIdentifier>' . ( time() ) . '</imsx_messageIdentifier>
    </imsx_POXRequestHeaderInfo>
  </imsx_POXHeader>
  <imsx_POXBody>
    <replaceResultRequest>
      <resultRecord>
        <sourcedGUID>
          <sourcedId>' . $source . '</sourcedId>
        </sourcedGUID>
        <result>
          <resultScore>
            <language>en-us</language>
            <textString>' . $grade . '</textString>
          </resultScore>
        </result>
      </resultRecord>
    </replaceResultRequest>
  </imsx_POXBody>
</imsx_POXEnvelopeRequest>';
}

/**
 * Creates an unique username
 *
 * @param string $consumerkey Consumer key
 * @param string $ltiuserid External tool user id
 *
 * @return string              The new username
 */
function local_ltiprovider_create_username( $consumerkey, $ltiuserid ) {

    if ( strlen( $ltiuserid ) > 0 and strlen( $consumerkey ) > 0 ) {
        $userkey = $consumerkey . ':' . $ltiuserid;
    } else {
        $userkey = false;
    }

    return 'ltiprovider' . md5( $consumerkey . '::' . $userkey );
}

/**
 * Unenrol an user from a course
 *
 * @param stdclass $tool Tool object
 * @param stdclass $user User object
 *
 * @return bool       True on unenroll
 */
function local_ltiprovider_unenrol_user( $tool, $user ) {
    global $DB;

    $course = $DB->get_record( 'course', array( 'id' => $tool->courseid ), '*', MUST_EXIST );
    $manual = enrol_get_plugin( 'manual' );

    if ( $instances = enrol_get_instances( $course->id, false ) ) {
        foreach ( $instances as $instance ) {
            if ( $instance->enrol === 'manual' ) {
                $manual->unenrol_user( $instance, $user->id );

                return true;
            }
        }
    }

    return false;
}

/**
 * Enrol a user in a course
 *
 * @param stdclass $tool The tool object
 * @param stdclass $user The user object
 * @param boolean $isinstructor Is instructor or not (learner).
 * @param boolean $return If we should return information
 *
 * @return mix          Boolean if $return is set to true
 */
function local_ltiprovider_enrol_user( $tool, $user, $isinstructor, $return = false ) {
    global $DB;

    if ( ( $isinstructor && ! $tool->enrolinst ) || ( ! $isinstructor && ! $tool->enrollearn ) ) {
        return $return;
    }

    $course = $DB->get_record( 'course', array( 'id' => $tool->courseid ), '*', MUST_EXIST );

    $manual = enrol_get_plugin( 'manual' );

    $today   = time();
    $today   = make_timestamp( date( 'Y', $today ), date( 'm', $today ), date( 'd', $today ), 0, 0, 0 );
    $timeend = 0;
    if ( $tool->enrolperiod ) {
        $timeend = $today + $tool->enrolperiod;
    }

    // Course role id for the Instructor or Learner
    // TODO Do something with lti system admin (urn:lti:sysrole:ims/lis/Administrator)
    $roleid = $isinstructor ? $tool->croleinst : $tool->crolelearn;

    if ( $instances = enrol_get_instances( $course->id, false ) ) {
        foreach ( $instances as $instance ) {
            if ( $instance->enrol === 'manual' ) {

                // Check if the user enrolment exists
                if ( ! $ue = $DB->get_record( 'user_enrolments',
                    array( 'enrolid' => $instance->id, 'userid' => $user->id ) ) ) {
                    // This means a new enrolment, so we have to check enroment starts and end limits and also max occupation

                    // First we check if there is a max enrolled limit
                    if ( $tool->maxenrolled ) {
                        // TODO Improve this count because unenrolled users from Moodle admin panel are not sync with this table
                        if ( $DB->count_records( 'local_ltiprovider_user',
                                array( 'toolid' => $tool->id ) ) > $tool->maxenrolled ) {
                            // We do not use print_error for the iframe issue allowframembedding
                            if ( $return ) {
                                return false;
                            } else {
                                echo get_string( 'maxenrolledreached', 'local_ltiprovider' );
                                die;
                            }
                        }
                    }

                    $timenow = time();
                    if ( $tool->enrolstartdate and $timenow < $tool->enrolstartdate ) {
                        // We do not use print_error for the iframe issue allowframembedding
                        if ( $return ) {
                            return false;
                        } else {
                            echo get_string( 'enrolmentnotstarted', 'local_ltiprovider' );
                            die;
                        }
                    }
                    if ( $tool->enrolenddate and $timenow > $tool->enrolenddate ) {
                        // We do not use print_error for the iframe issue allowframembedding
                        if ( $return ) {
                            return false;
                        } else {
                            echo get_string( 'enrolmentfinished', 'local_ltiprovider' );
                            die;
                        }
                    }
                    // TODO, delete created users not enrolled

                    $manual->enrol_user( $instance, $user->id, $roleid, $today, $timeend );
                    if ( $return ) {
                        return true;
                    }
                }
                break;
            }
        }
    }

    return false;
}

/**
 * Populates a standar user record
 *
 * @param stdClass $user The user record to be populated
 * @param stdClass $context The LTI context
 * @param stdClass $tool The tool object
 */
function local_ltiprovider_populate( $user, $context, $tool ) {
    global $CFG;
    $user->firstname    = isset( $context->info['lis_person_name_given'] ) ? $context->info['lis_person_name_given'] : $context->info['user_id'];
    $user->lastname     = isset( $context->info['lis_person_name_family'] ) ? $context->info['lis_person_name_family'] : $context->info['context_id'];
    $user->email        = clean_param( $context->getUserEmail(), PARAM_EMAIL );
    $user->city         = ( ! empty( $tool->city ) ) ? $tool->city : "";
    $user->country      = ( ! empty( $tool->country ) ) ? $tool->country : "";
    $user->institution  = ( ! empty( $tool->institution ) ) ? $tool->institution : "";
    $user->timezone     = ( ! empty( $tool->timezone ) ) ? $tool->timezone : "";
    $user->maildisplay  = ( ! empty( $tool->maildisplay ) ) ? $tool->maildisplay : "";
    $user->mnethostid   = $CFG->mnet_localhost_id;
    $user->confirmed    = 1;
    $user->timecreated  = time();
    $user->timemodified = time();

    $user->lang = $tool->lang;
    if ( ! $user->lang and isset( $_POST['launch_presentation_locale'] ) ) {
        $user->lang = optional_param( 'launch_presentation_locale', '', PARAM_LANG );
    }
    if ( ! $user->lang ) {
        // TODO: This should be changed for detect the course lang
        $user->lang = current_language();
    }
}

/**
 * Compares two users
 *
 * @param stdClass $newuser The new user
 * @param stdClass $olduser The old user
 *
 * @return bool                True if both users are the same
 */
function local_ltiprovider_user_match( $newuser, $olduser ) {
    if ( $newuser->firstname != $olduser->firstname )
        return false;
    if ( $newuser->lastname != $olduser->lastname )
        return false;
    if ( $newuser->email != $olduser->email )
        return false;
    if ( $newuser->city != $olduser->city )
        return false;
    if ( $newuser->country != $olduser->country )
        return false;
    if ( $newuser->institution != $olduser->institution )
        return false;
    if ( $newuser->timezone != $olduser->timezone )
        return false;
    if ( $newuser->maildisplay != $olduser->maildisplay )
        return false;
    if ( $newuser->mnethostid != $olduser->mnethostid )
        return false;
    if ( $newuser->confirmed != $olduser->confirmed )
        return false;
    if ( $newuser->lang != $olduser->lang )
        return false;

    return true;
}

/**
 * For new created courses we get the fullname, shortname or idnumber according global settings
 *
 * @param string $field The course field to get (fullname, shortname or idnumber)
 * @param stdClass $context The global LTI context
 *
 * @return string          The field
 */
function local_ltiprovider_get_new_course_info( $field, $context ) {
    global $DB;

    $info = '';

    $setting = get_config( 'local_ltiprovider', $field . "format" );

    switch ( $setting ) {
        case 0:
            $info = $context->info['context_id'];
            break;
        case '1':
            $info = $context->info['context_title'];
            break;
        case '2':
            $info = $context->info['context_label'];
            break;
        case '3':
            $info = $context->info['oauth_consumer_key'] . ':' . $context->info['context_id'];
            break;
        case '4':
            $info = $context->info['oauth_consumer_key'] . ':' . $context->info['context_title'];
            break;
        case '5':
            $info = $context->info['oauth_consumer_key'] . ':' . $context->info['context_label'];
            break;
        case '6':
            $info = local_ltiprovider_get_custom_new_course_info( $field, $context );
            break;
    }

    // Special case.
    if ( $field == 'shortname' ) {
        // Add or increase the number at the final of the shortname.
        if ( $course = $DB->get_record( 'course', array( 'shortname' => $info ) ) ) {
            if ( $samecourses = $DB->get_records( 'course', array( 'fullname' => $course->fullname ), 'id DESC',
                'shortname', '0', '1' ) ) {
                $samecourse = array_shift( $samecourses );
                $parts      = explode( ' ', $samecourse->shortname );
                $number     = array_pop( $parts );
                if ( is_numeric( $number ) ) {
                    $parts[] = $number + 1;
                } else {
                    $parts[] = $number . ' 1';
                }
                $info = implode( ' ', $parts );
            }
        }
    }

    return $info;
}

/**
 * Generate a custom string to LTI key field (shortname, fullname or idnumber)
 *
 * @param $field
 * @param $context
 *
 * @return string
 * @throws dml_exception
 * @throws moodle_exception
 */
function local_ltiprovider_get_custom_new_course_info( $field, $context ) {
    $value        = '';
    $customformat = trim( get_config( 'local_ltiprovider', $field . "formatcustom" ) );
    if ( $customformat ) {
        $separators = array( ':', ' ' );
        foreach ( $separators as $separator ) {
            $customformatarray = explode( $separator, $customformat );
            foreach ( $customformatarray as $item ) {
                $value = local_ltiprovider_get_custom_new_course_info_add_param( $value, $item, $context, $separator );
            }
        }
    }

    if ( empty( $value ) ) {
        $error        = new stdClass();
        $error->field = $field . 'formatcustom';
        print_error( get_string( 'cantgeneratecustomltikey', 'local_ltiprovider', $error ) );
    }

    return $value;

}

function local_ltiprovider_get_custom_new_course_info_add_param( $value, $item, $context, $separator ) {
    $item = trim( $item );
    if ( ! empty( $item ) && ! empty( $context->info[ $item ] ) ) {
        $value .= ( empty( $value ) ? '' : $separator ) . $context->info[ $item ];
    }

    return $value;
}

/**
 * Create a ltiprovier tool for a restored course or activity
 *
 * @param int $courseid The course id
 * @param int $contextid The context id
 * @param stdClass $lticontext The LTI context object
 *
 * @return int           The new tool id
 */
function local_ltiprovider_create_tool( $courseid, $contextid, $lticontext ) {
    global $CFG, $DB;

    $tool                    = new stdClass();
    $tool->courseid          = $courseid;
    $tool->contextid         = $contextid;
    $tool->disabled          = 0;
    $tool->forcenavigation   = 0;
    $tool->croleinst         = 3;
    $tool->crolelearn        = 5;
    $tool->aroleinst         = 3;
    $tool->arolelearn        = 5;
    $tool->secret            = get_config( 'local_ltiprovider', 'globalsharedsecret' );
    $tool->encoding          = 'UTF-8';
    $tool->institution       = "";
    $tool->lang              = $CFG->lang;
    $tool->timezone          = 99;
    $tool->maildisplay       = 2;
    $tool->city              = "mycity";
    $tool->country           = "ES";
    $tool->hidepageheader    = 0;
    $tool->hidepagefooter    = 0;
    $tool->hideleftblocks    = 0;
    $tool->hiderightblocks   = 0;
    $tool->customcss         = '';
    $tool->enrolstartdate    = 0;
    $tool->enrolperiod       = 0;
    $tool->enrolenddate      = 0;
    $tool->maxenrolled       = 0;
    $tool->userprofileupdate = 1;
    $tool->timemodified      = time();
    $tool->timecreated       = time();
    $tool->lastsync          = 0;
    $tool->enrolinst         = 1;
    $tool->enrollearn        = 1;
    $tool->sendgrades        = ( ! empty( $lticontext->info['lis_outcome_service_url'] ) ) ? 1 : 0;
    $tool->syncmembers       = ( ! empty( $lticontext->info['ext_ims_lis_memberships_url'] ) ) ? 1 : 0;
    $tool->syncmode          = ( ! empty( $lticontext->info['ext_ims_lis_memberships_url'] ) ) ? 1 : 0;
    $tool->syncperiod        = ( ! empty( $lticontext->info['ext_ims_lis_memberships_url'] ) ) ? 86400 : 0;

    $tool->id = $DB->insert_record( 'local_ltiprovider', $tool );

    return $tool;
}

/**
 * Duplicate a course
 *
 * @param int $courseid
 * @param string $fullname Duplicated course fullname
 * @param int $newcourse Destination course
 * @param array $options List of backup options
 *
 * @return stdClass New course info
 */
function local_ltiprovider_duplicate_course(
    $courseid,
    $newcourse,
    $visible = 1,
    $options = array(),
    $useridcreating = null,
    $context
) {
    global $CFG, $USER, $DB;

    require_once( $CFG->dirroot . '/backup/util/includes/backup_includes.php' );
    require_once( $CFG->dirroot . '/backup/util/includes/restore_includes.php' );

    if ( empty( $USER ) ) {
        // Emulate session.
        cron_setup_user();
    }

    // Context validation.

    if ( ! ( $course = $DB->get_record( 'course', array( 'id' => $courseid ) ) ) ) {
        throw new moodle_exception( 'invalidcourseid', 'error' );
    }

    $removeoptions                              = array();
    $removeoptions['keep_roles_and_enrolments'] = true;
    $removeoptions['keep_groups_and_groupings'] = true;
    remove_course_contents( $newcourse->id, false, $removeoptions );

    $backupdefaults = array(
        'activities'       => 1,
        'blocks'           => 1,
        'filters'          => 1,
        'users'            => 0,
        'role_assignments' => 0,
        'comments'         => 0,
        'userscompletion'  => 0,
        'logs'             => 0,
        'grade_histories'  => 0
    );

    $backupsettings = array();
    // Check for backup and restore options.
    if ( ! empty( $options ) ) {
        foreach ( $options as $option ) {

            // Strict check for a correct value (allways 1 or 0, true or false).
            $value = clean_param( $option['value'], PARAM_INT );

            if ( $value !== 0 and $value !== 1 ) {
                throw new moodle_exception( 'invalidextparam', 'webservice', '', $option['name'] );
            }

            if ( ! isset( $backupdefaults[ $option['name'] ] ) ) {
                throw new moodle_exception( 'invalidextparam', 'webservice', '', $option['name'] );
            }

            $backupsettings[ $option['name'] ] = $value;
        }
    }


    // Backup the course.
    $admin = get_admin();

    $bc = new backup_controller( backup::TYPE_1COURSE, $course->id, backup::FORMAT_MOODLE,
        backup::INTERACTIVE_NO, backup::MODE_SAMESITE, $admin->id );

    foreach ( $backupsettings as $name => $value ) {
        $bc->get_plan()->get_setting( $name )->set_value( $value );
    }

    $backupid       = $bc->get_backupid();
    $backupbasepath = $bc->get_plan()->get_basepath();

    $bc->execute_plan();
    $results = $bc->get_results();
    $file    = $results['backup_destination'];

    $bc->destroy();

    // Restore the backup immediately.

    // Check if we need to unzip the file because the backup temp dir does not contains backup files.
    if ( ! file_exists( $backupbasepath . "/moodle_backup.xml" ) ) {
        $file->extract_to_pathname( get_file_packer( 'application/vnd.moodle.backup' ), $backupbasepath );
    }

    $rc = new restore_controller( $backupid, $newcourse->id,
        backup::INTERACTIVE_NO, backup::MODE_SAMESITE, $admin->id, backup::TARGET_CURRENT_DELETING );

    foreach ( $backupsettings as $name => $value ) {
        $setting = $rc->get_plan()->get_setting( $name );
        if ( $setting->get_status() == backup_setting::NOT_LOCKED ) {
            $setting->set_value( $value );
        }
    }

    if ( ! $rc->execute_precheck() ) {
        $precheckresults = $rc->get_precheck_results();
        if ( is_array( $precheckresults ) && ! empty( $precheckresults['errors'] ) ) {
            if ( empty( $CFG->keeptempdirectoriesonbackup ) ) {
                fulldelete( $backupbasepath );
            }

            $errorinfo = '';

            foreach ( $precheckresults['errors'] as $error ) {
                $errorinfo .= $error;
            }

            if ( array_key_exists( 'warnings', $precheckresults ) ) {
                foreach ( $precheckresults['warnings'] as $warning ) {
                    $errorinfo .= $warning;
                }
            }

            throw new moodle_exception( 'backupprecheckerrors', 'webservice', '', $errorinfo );
        }
    }

    $rc->execute_plan();
    $rc->destroy();

    $course            = $DB->get_record( 'course', array( 'id' => $newcourse->id ), '*', MUST_EXIST );
    $course->visible   = $visible;
    $course->fullname  = $newcourse->fullname;
    $course->shortname = $newcourse->shortname;
    $course->idnumber  = $newcourse->idnumber;

    // Set shortname and fullname back.
    $DB->update_record( 'course', $course );

    if ( empty( $CFG->keeptempdirectoriesonbackup ) ) {
        fulldelete( $backupbasepath );
    }

    // Delete the course backup file created by this WebService. Originally located in the course backups area.
    $file->delete();

    // We have to unenroll all the user except the one that create the course.
    if ( get_config( 'local_ltiprovider', 'duplicatecourseswithoutusers' ) and $useridcreating ) {
        require_once( $CFG->dirroot . '/group/lib.php' );
        // Previous to unenrol users, we assign some type of activities to the user that created the course.
        if ( $user = $DB->get_record( 'user', array( 'id' => $useridcreating ) ) ) {
            if ( $databases = $DB->get_records( 'data', array( 'course' => $course->id ) ) ) {
                foreach ( $databases as $data ) {
                    $DB->execute( "UPDATE {data_records} SET userid = ? WHERE dataid = ?", array(
                        $user->id,
                        $data->id
                    ) );
                }
            }
            if ( $glossaries = $DB->get_records( 'glossary', array( 'course' => $course->id ) ) ) {
                foreach ( $glossaries as $glossary ) {
                    $DB->execute( "UPDATE {glossary_entries} SET userid = ? WHERE glossaryid = ?", array(
                        $user->id,
                        $glossary->id
                    ) );
                }
            }

            // Same for questions.
            $newcoursecontextid = context_course::instance( $course->id );
            if ( $qcategories = $DB->get_records( 'question_categories',
                array( 'contextid' => $newcoursecontextid->id ) ) ) {
                foreach ( $qcategories as $qcategory ) {
                    $DB->execute( "UPDATE {question} SET createdby = ?, modifiedby = ? WHERE category = ?", array(
                        $user->id,
                        $user->id,
                        $qcategory->id
                    ) );
                }
            }

            // Enrol the user.
            if ( $tool = $DB->get_record( 'local_ltiprovider', array( 'contextid' => $newcoursecontextid->id ) ) ) {
                $roles        = strtolower( $context->info['roles'] );
                $isInstructor = false;
                if ( ! ( strpos( $roles, "instructor" ) === false ) )
                    $isInstructor = true;
                if ( ! ( strpos( $roles, "administrator" ) === false ) )
                    $isInstructor = true;
                local_ltiprovider_enrol_user( $tool, $user, $isInstructor, true );
            }


            // Now, we unenrol all the users except the one who created the course.
            $plugins   = enrol_get_plugins( true );
            $instances = enrol_get_instances( $course->id, true );
            foreach ( $instances as $key => $instance ) {
                if ( ! isset( $plugins[ $instance->enrol ] ) ) {
                    unset( $instances[ $key ] );
                    continue;
                }
            }

            $sql    = "SELECT ue.*
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON (e.id = ue.enrolid AND e.courseid = :courseid)
                          JOIN {context} c ON (c.contextlevel = :courselevel AND c.instanceid = e.courseid)";
            $params = array( 'courseid' => $course->id, 'courselevel' => CONTEXT_COURSE );

            $rs = $DB->get_recordset_sql( $sql, $params );
            foreach ( $rs as $ue ) {
                if ( $ue->userid == $user->id ) {
                    continue;
                }

                if ( ! isset( $instances[ $ue->enrolid ] ) ) {
                    continue;
                }
                $instance = $instances[ $ue->enrolid ];
                $plugin   = $plugins[ $instance->enrol ];
                if ( ! $plugin->allow_unenrol( $instance ) and ! $plugin->allow_unenrol_user( $instance, $ue ) ) {
                    continue;
                }
                $plugin->unenrol_user( $instance, $ue->userid );
            }
            $rs->close();

            groups_delete_group_members( $course->id );
            groups_delete_groups( $course->id, false );
            groups_delete_groupings_groups( $course->id, false );
            groups_delete_groupings( $course->id, false );
        }
    }

    return $course;
}

/**
 * Duplicates a Moodle module in an existing course
 *
 * @param int $cmid Course module id
 * @param int $courseid Course id
 *
 * @return int           New course module id
 */
function local_ltiprovider_duplicate_module( $cmid, $courseid, $newidnumber, $lticontext ) {
    global $CFG, $DB, $USER;

    require_once( $CFG->dirroot . '/backup/util/includes/backup_includes.php' );
    require_once( $CFG->dirroot . '/backup/util/includes/restore_includes.php' );
    require_once( $CFG->libdir . '/filelib.php' );

    if ( empty( $USER ) ) {
        // Emulate session.
        cron_setup_user();
    }

    $course    = $DB->get_record( 'course', array( 'id' => $courseid ), '*', MUST_EXIST );
    $cm        = get_coursemodule_from_id( '', $cmid, 0, true, MUST_EXIST );
    $cmcontext = context_module::instance( $cm->id );
    $context   = context_course::instance( $course->id );


    if ( ! plugin_supports( 'mod', $cm->modname, FEATURE_BACKUP_MOODLE2 ) ) {
        $url = course_get_url( $course, $cm->sectionnum );
        print_error( 'duplicatenosupport', 'error', $url );
    }

    // backup the activity
    $admin = get_admin();

    $bc = new backup_controller( backup::TYPE_1ACTIVITY, $cm->id, backup::FORMAT_MOODLE,
        backup::INTERACTIVE_NO, backup::MODE_IMPORT, $admin->id );

    $backupid       = $bc->get_backupid();
    $backupbasepath = $bc->get_plan()->get_basepath();

    $bc->execute_plan();

    $bc->destroy();

    // restore the backup immediately

    $rc = new restore_controller( $backupid, $courseid,
        backup::INTERACTIVE_NO, backup::MODE_IMPORT, $admin->id, backup::TARGET_CURRENT_ADDING );

    if ( ! $rc->execute_precheck() ) {
        $precheckresults = $rc->get_precheck_results();
        if ( is_array( $precheckresults ) && ! empty( $precheckresults['errors'] ) ) {
            if ( empty( $CFG->keeptempdirectoriesonbackup ) ) {
                fulldelete( $backupbasepath );
            }
            print_r( $precheckresults );
            die();
        }
    }

    $rc->execute_plan();

    $newcmid = null;
    $tasks   = $rc->get_plan()->get_tasks();
    foreach ( $tasks as $task ) {
        if ( is_subclass_of( $task, 'restore_activity_task' ) ) {
            if ( $task->get_old_contextid() == $cmcontext->id ) {
                $newcmid = $task->get_moduleid();
                break;
            }
        }
    }

    $rc->destroy();


    if ( ! $DB->get_record( 'course_modules', array( 'idnumber' => $newidnumber ) ) ) {
        if ( $module = $DB->get_record( 'course_modules', array( 'id' => $newcmid ) ) ) {
            $module->idnumber = $newidnumber;
            $DB->update_record( 'course_modules', $module );
        }
    } else {
        if ( empty( $CFG->keeptempdirectoriesonbackup ) ) {
            fulldelete( $backupbasepath );
        }

        return $newcmid;
    }


    $newtoolid    = 0;
    $newcmcontext = context_module::instance( $newcmid );
    if ( $tools = $DB->get_records( 'local_ltiprovider', array( 'contextid' => $cmcontext->id ) ) ) {
        foreach ( $tools as $tool ) {
            $tool->courseid  = $course->id;
            $tool->contextid = $newcmcontext->id;
            $newtoolid       = $DB->insert_record( 'local_ltiprovider', $tool );
        }
    }

    if ( ! $newtoolid ) {
        $tool = local_ltiprovider_create_tool( $course->id, $newcmcontext->id, $lticontext );
    }

    if ( empty( $CFG->keeptempdirectoriesonbackup ) ) {
        fulldelete( $backupbasepath );
    }

    return $newcmid;

}

function local_ltiprovider_update_user_profile_image( $userid, $url ) {
    global $CFG, $DB;

    require_once( "$CFG->libdir/filelib.php" );
    require_once( "$CFG->libdir/gdlib.php" );

    $fs = get_file_storage();
    try {
        $context = context_user::instance( $userid, MUST_EXIST );
        $fs->delete_area_files( $context->id, 'user', 'newicon' );

        $filerecord = array(
            'contextid' => $context->id,
            'component' => 'user',
            'filearea'  => 'newicon',
            'itemid'    => 0,
            'filepath'  => '/'
        );
        if ( ! $iconfiles = $fs->create_file_from_url( $filerecord, $url, array(
            'calctimeout'    => false,
            'timeout'        => 5,
            'skipcertverify' => true,
            'connecttimeout' => 5
        ) ) ) {
            return "Error downloading profile image from $url";
        }

        if ( $iconfiles = $fs->get_area_files( $context->id, 'user', 'newicon' ) ) {
            // Get file which was uploaded in draft area
            foreach ( $iconfiles as $file ) {
                if ( ! $file->is_directory() ) {
                    break;
                }
            }
            // Copy file to temporary location and the send it for processing icon
            if ( $iconfile = $file->copy_content_to_temp() ) {
                // There is a new image that has been uploaded
                // Process the new image and set the user to make use of it.
                $newpicture = (int) process_new_icon( $context, 'user', 'icon', 0, $iconfile );
                // Delete temporary file
                @unlink( $iconfile );
                // Remove uploaded file.
                $fs->delete_area_files( $context->id, 'user', 'newicon' );
                $DB->set_field( 'user', 'picture', $newpicture, array( 'id' => $userid ) );

                return true;
            } else {
                // Something went wrong while creating temp file.
                // Remove uploaded file.
                $fs->delete_area_files( $context->id, 'user', 'newicon' );

                return "Error creating the downloaded profile image from $url";
            }
        } else {
            return "Error converting downloaded profile image from $url";
        }
    } catch ( Exception $e ) {
        return "Error downloading profile image from $url";
    }

    return "Error downloading profile image from $url";
}

function local_ltiprovider_add_user_to_group( $tool, $user ) {
    global $CFG;

    if ( $tool->addtogroup ) {
        require_once( $CFG->libdir . '/grouplib.php' );
        require_once( $CFG->dirroot . '/group/lib.php' );

        if ( strpos( $tool->addtogroup, 'request:' ) === 0 ) {
            $parameter = str_replace( 'request:', '', $tool->addtogroup );
            if ( ! isset( $_REQUEST[ $parameter ] ) ) {
                return;
            }
            $groupidnumber = $_REQUEST[ $parameter ];
        } else {
            $groupidnumber = $tool->addtogroup;
        }

        if ( ! $group = groups_get_group_by_idnumber( $tool->courseid, $groupidnumber ) ) {
            $group           = new stdClass();
            $group->courseid = $tool->courseid;
            $group->name     = $groupidnumber;
            $group->idnumber = $groupidnumber;
            $group->id       = groups_create_group( $group );
        }

        groups_add_member( $group->id, $user->id );
    }
}

/**
 * This function executes membership service for the current tool
 *
 * @param $tool
 * @param $timenow
 * @param $userphotos array of current userphotos
 * @param $consumers array of consumers that have already been made
 *
 * @return array with keys userphotos, consumers and response
 */
function local_ltiprovider_membership_service( $tool, $timenow, $userphotos, $consumers ) {
    global $DB, $CFG;
    mtrace( 'Starting sync of tool: ' . $tool->id );
    $response                 = "";
    $update_last_sync_members = false;
    // We check for all the users, notice that users can access the same tool from different consumers.
    if ( $users = $DB->get_records( 'local_ltiprovider_user', array( 'toolid' => $tool->id ), 'lastaccess DESC' ) ) {

        foreach ( $users as $user ) {
            if ( ! $user->membershipsurl or ! $user->membershipsid ) {
                continue;
            }

            $consumer = md5( $tool->id . ':' . $user->membershipsurl . ':' . $user->consumerkey . ':' . $user->consumersecret );
            if ( in_array( $consumer, $consumers ) ) {
                // We had syncrhonized with this consumer yet.
                continue;
            }

            $params = array(
                'lti_message_type' => 'basic-lis-readmembershipsforcontext',
                'id'               => $user->membershipsid,
                'lti_version'      => 'LTI-1p0'
            );

            mtrace( 'Calling memberships url: ' . $user->membershipsurl . ' with body: ' . json_encode( $params ) );

            try {
                $response = ltiprovider\sendOAuthParamsPOST( 'POST', $user->membershipsurl, $user->consumerkey,
                    $user->consumersecret,
                    'application/x-www-form-urlencoded', $params );
            } catch ( Exception $e ) {
                mtrace( "Exception: " . $e->getMessage() );
                $response = false;
            }

            if ( $response ) {
                $consumers[] = $consumer;

                $data = new SimpleXMLElement( $response );
                if ( ! empty( $data->statusinfo ) ) {

                    if ( strpos( strtolower( $data->statusinfo->codemajor ), 'success' ) !== false ) {
                        $update_last_sync_members = true;
                        $members                  = $data->memberships->member;
                        mtrace( count( $members ) . ' members received' );
                        $currentusers = array();
                        foreach ( $members as $member ) {
                            $username = local_ltiprovider_create_username( $user->consumerkey, $member->user_id );

                            $userobj = $DB->get_record( 'user', array( 'username' => $username ) );
                            if ( ! $userobj ) {
                                // Old format.
                                $oldusername = 'ltiprovider' . md5( $user->consumerkey . ':' . $member->user_id );
                                $userobj     = $DB->get_record( 'user', array( 'username' => $oldusername ) );
                                if ( $userobj ) {
                                    $DB->set_field( 'user', 'username', $username, array( 'id' => $userobj->id ) );
                                }
                                $userobj = $DB->get_record( 'user', array( 'username' => $username ) );
                            }

                            if ( $userobj ) {
                                $currentusers[] = $userobj->id;
                                $firstname      = clean_param( $member->person_name_given, PARAM_TEXT );
                                $lastname       = clean_param( $member->person_name_family, PARAM_TEXT );
                                $email          = clean_param( $member->person_contact_email_primary, PARAM_EMAIL );
                                if ( $firstname !== $userobj->firstname || $lastname !== $userobj->lastname
                                    || $email !== $userobj->email ) {

                                    $userobj->firstname    = clean_param( $member->person_name_given, PARAM_TEXT );
                                    $userobj->lastname     = clean_param( $member->person_name_family, PARAM_TEXT );
                                    $userobj->email        = clean_param( $member->person_contact_email_primary,
                                        PARAM_EMAIL );
                                    $userobj->timemodified = time();

                                    $DB->update_record( 'user', $userobj );
                                    $userphotos[ $userobj->id ] = $member->user_image;

                                    // Trigger event.
                                    $event = \core\event\user_updated::create(
                                        array(
                                            'objectid'      => $userobj->id,
                                            'relateduserid' => $userobj->id,
                                            'context'       => context_user::instance( $userobj->id )
                                        )
                                    );
                                    $event->trigger();
                                }

                            } else {
                                // New members.
                                if ( $tool->syncmode == 1 or $tool->syncmode == 2 ) {
                                    // We have to enrol new members so we have to create it.
                                    $userobj = new stdClass();
                                    // clean_param , email username text
                                    $auth = get_config( 'local_ltiprovider', 'defaultauthmethod' );
                                    if ( $auth ) {
                                        $userobj->auth = $auth;
                                    } else {
                                        $userobj->auth = 'nologin';
                                    }

                                    $username             = local_ltiprovider_create_username( $user->consumerkey,
                                        $member->user_id );
                                    $userobj->username    = $username;
                                    $userobj->password    = md5( uniqid( rand(), 1 ) );
                                    $userobj->firstname   = clean_param( $member->person_name_given, PARAM_TEXT );
                                    $userobj->lastname    = clean_param( $member->person_name_family, PARAM_TEXT );
                                    $userobj->email       = clean_param( $member->person_contact_email_primary,
                                        PARAM_EMAIL );
                                    $userobj->city        = $tool->city;
                                    $userobj->country     = $tool->country;
                                    $userobj->institution = $tool->institution;
                                    $userobj->timezone    = $tool->timezone;
                                    $userobj->maildisplay = $tool->maildisplay;
                                    $userobj->mnethostid  = $CFG->mnet_localhost_id;
                                    $userobj->confirmed   = 1;
                                    $userobj->lang        = $tool->lang;
                                    $userobj->timecreated = time();
                                    if ( ! $userobj->lang ) {
                                        // TODO: This should be changed for detect the course lang
                                        $userobj->lang = current_language();
                                    }

                                    $userobj->id = $DB->insert_record( 'user', $userobj );
                                    // Reload full user
                                    $userobj = $DB->get_record( 'user', array( 'id' => $userobj->id ) );

                                    $userphotos[ $userobj->id ] = $member->user_image;
                                    // Trigger event.
                                    $event = \core\event\user_created::create(
                                        array(
                                            'objectid'      => $userobj->id,
                                            'relateduserid' => $userobj->id,
                                            'context'       => context_user::instance( $userobj->id )
                                        )
                                    );
                                    $event->trigger();

                                    $currentusers[] = $userobj->id;
                                }
                            }
                            // 1 -> Enrol and unenrol, 2 -> enrol
                            if ( $tool->syncmode == 1 or $tool->syncmode == 2 ) {
                                // Enroll the user in the course. We don't know if it was previously unenrolled.
                                $roles        = strtolower( $member->roles );
                                $isInstructor = false;
                                if ( ! ( strpos( $roles, "instructor" ) === false ) )
                                    $isInstructor = true;
                                if ( ! ( strpos( $roles, "administrator" ) === false ) )
                                    $isInstructor = true;

                                local_ltiprovider_enrol_user( $tool, $userobj, $isInstructor, true );
                            }
                        }
                        // Now we check if we have to unenrol users for keep both systems sync.
                        if ( $tool->syncmode == 1 or $tool->syncmode == 3 ) {
                            // Unenrol users also.
                            $context = context_course::instance( $tool->courseid );
                            $eusers  = get_enrolled_users( $context );
                            foreach ( $eusers as $euser ) {
                                if ( ! in_array( $euser->id, $currentusers ) ) {
                                    local_ltiprovider_unenrol_user( $tool, $euser );
                                }
                            }
                        }
                    } else {
                        mtrace( 'Error recived from the remote system: ' . $data->statusinfo->codemajor . ' ' . $data->statusinfo->severity . ' ' . $data->statusinfo->codeminor );
                    }
                } else {
                    mtrace( 'Error parsing the XML received' . substr( $response, 0,
                            125 ) . '... (Displaying only 125 chars)' );
                }
            } else {
                mtrace( 'No response received from ' . $user->membershipsurl );
            }
        }
        if ( $update_last_sync_members ) {
            set_config( 'membershipslastsync-' . $tool->id, $timenow, 'local_ltiprovider' );
        }
        mtrace( 'Finished sync of member using the memberships service' );
    }

    return array( 'userphotos' => $userphotos, 'consumers' => $consumers, 'response' => $response );
}

/**
 * Gets the user photos and add it to each user
 *
 * @param $userphotos
 */
function local_ltiprovider_membership_service_update_userphotos( $userphotos ) {

    // Sync of user photos.
    mtrace( "Sync user profile images" );
    $counter = 0;
    if ( $userphotos ) {
        foreach ( $userphotos as $userid => $url ) {
            if ( $url ) {
                $result = local_ltiprovider_update_user_profile_image( $userid, $url );
                if ( $result === true ) {
                    $counter ++;
                    mtrace( "Profile image succesfully downloaded and created from $url" );
                } else {
                    mtrace( $result );
                }
            }
        }
    }
    mtrace( "$counter profile images updated" );
}

function local_ltiprovier_do_grades_sync( $tool, $users, $timenow, $force_send = false ) {
    $log = array();
    global $DB;
    $log[] = " Starting sync tool for grades id $tool->id course id $tool->courseid";
    if ( $tool->requirecompletion ) {
        $log[] = "  Grades require activity or course completion";
    }
    $user_count  = 0;
    $send_count  = 0;
    $error_count = 0;

    $completion = new \completion_info( get_course( $tool->courseid ) );

    $do_update_last_sync = false;

    if ( $users ) {
        foreach ( $users as $user ) {

            $data = array(
                'tool' => $tool,
                'user' => $user,
            );
            if (local_ltiprovider_call_hook( 'grades', (object) $data )) {

                $user_count = $user_count + 1;
                // This can happen is the sync process has an unexpected error
                if ( strlen( $user->serviceurl ) < 1 ) {
                    $log[] = "   Empty serviceurl";
                    continue;
                }
                if ( strlen( $user->sourceid ) < 1 ) {
                    $log[] = "   Empty sourceid";
                    continue;
                }

                if ( !$force_send && $user->lastsync > $tool->lastsync ) {
                    $log[] = "   Skipping user {$user->id} due to recent sync";
                    continue;
                }

                $grade = false;
                if ( $context = $DB->get_record( 'context', array( 'id' => $tool->contextid ) ) ) {
                    if ( $context->contextlevel == CONTEXT_COURSE ) {

                        if ( $tool->requirecompletion and ! $completion->is_course_complete( $user->userid ) ) {
                            $log[] = "   Skipping user $user->userid since he didn't complete the course";
                            continue;
                        }

                        if ( $tool->sendcompletion ) {
                            $grade    = $completion->is_course_complete( $user->userid ) ? 1 : 0;
                            $grademax = 1;
                        } else if ( $grade = grade_get_course_grade( $user->userid, $tool->courseid ) ) {
                            $grademax = floatval( $grade->item->grademax );
                            $grade    = $grade->grade;
                        }
                    } else if ( $context->contextlevel == CONTEXT_MODULE ) {
                        $cm = get_coursemodule_from_id( false, $context->instanceid, 0, false, MUST_EXIST );

                        if ( $tool->requirecompletion ) {
                            $data = $completion->get_data( $cm, false, $user->userid );
                            if ( $data->completionstate != COMPLETION_COMPLETE_PASS and $data->completionstate != COMPLETION_COMPLETE ) {
                                $log[] = "   Skipping user $user->userid since he didn't complete the activity";
                                continue;
                            }
                        }

                        if ( $tool->sendcompletion ) {
                            $data = $completion->get_data( $cm, false, $user->userid );
                            if ( $data->completionstate == COMPLETION_COMPLETE_PASS ||
                                $data->completionstate == COMPLETION_COMPLETE ||
                                $data->completionstate == COMPLETION_COMPLETE_FAIL ) {
                                $grade = 1;
                            } else {
                                $grade = 0;
                            }
                            $grademax = 1;
                        } else {
                            $grades = grade_get_grades( $cm->course, 'mod', $cm->modname, $cm->instance,
                                $user->userid );
                            if ( empty( $grades->items[0]->grades ) ) {
                                $grade = false;
                            } else {
                                $grade = reset( $grades->items[0]->grades );
                                if ( ! empty( $grade->item ) ) {
                                    $grademax = floatval( $grade->item->grademax );
                                } else {
                                    $grademax = floatval( $grades->items[0]->grademax );
                                }
                                $grade = $grade->grade;
                            }
                        }
                    }

                    if ( $grade === false || $grade === null || strlen( $grade ) < 1 ) {
                        $log[] = "   Invalid grade $grade";
                        continue;
                    }

                    // No need to be dividing by zero
                    if ( $grademax == 0.0 ) {
                        $grademax = 100.0;
                    }

                    // TODO: Make lastgrade should be float or string - but it is integer so we truncate
                    // TODO: Then remove those intval() calls

                    // Don't double send
                    if ( intval( $grade ) == $user->lastgrade ) {
                        $log[] = "   Skipping, last grade send is equal to current grade";
                        continue;
                    }

                    // We sync with the external system only when the new grade differs with the previous one
                    // TODO - Global setting for check this
                    if ( $grade >= 0 and $grade <= $grademax ) {
                        $float_grade = $grade / $grademax;
                        $body        = local_ltiprovider_create_service_body( $user->sourceid, $float_grade );

                        try {
                            $response = ltiprovider\sendOAuthBodyPOST( 'POST', $user->serviceurl,
                                $user->consumerkey, $user->consumersecret, 'application/xml', $body );
                        } catch ( Exception $e ) {
                            mtrace( " " . $e->getMessage() );
                            $error_count = $error_count + 1;
                            continue;
                        }

                        // TODO - Check for errors in $retval in a correct way (parsing xml)
                        if ( strpos( strtolower( $response ), 'success' ) !== false ) {
                            $do_update_last_sync = true;

                            $DB->set_field( 'local_ltiprovider_user', 'lastsync', $timenow,
                                array( 'id' => $user->id ) );
                            $DB->set_field( 'local_ltiprovider_user', 'lastgrade', intval( $grade ),
                                array( 'id' => $user->id ) );
                            $log[] = " User grade sent to remote system. userid: $user->userid grade: $float_grade";
                            $send_count = $send_count + 1;
                        } else {
                            $log[]       = " User grade send failed. userid: $user->userid grade: $float_grade: " . $response;
                            $error_count = $error_count + 1;
                        }
                    } else {
                        $log[]       = " User grade for user $user->userid out of range: grade = " . $grade;
                        $error_count = $error_count + 1;
                    }
                } else {
                    $log[] = " Invalid context: contextid = " . $tool->contextid;
                }
            } else {
                $log[] = " Extensions return invalid result for {$user->id} and tool " . $tool->contextid;
            }
        }
    }
    $log[] = " Completed sync tool id $tool->id course id $tool->courseid users=$user_count sent=$send_count errors=$error_count";
    if ( $do_update_last_sync ) {
        $DB->set_field( 'local_ltiprovider', 'lastsync', $timenow, array( 'id' => $tool->id ) );
    }

    return $log;
}