<?php
if (!defined('ISSABELPBX_IS_AUTH')) { die('No direct script access allowed'); }
//    License for all code of this IssabelPBX module can be found in the license file inside the module directory
//    Copyright 2013 Schmooze Com Inc.
//    Copyright 2022 Issabel Foundation


// The destinations this module provides
// returns a associative arrays with keys 'destination' and 'description'
function ivr_destinations() {
    global $module_page;

    //get the list of IVR's
    $results = ivr_get_details();

    // return an associative array with destination and description
    if (isset($results)) {
        foreach($results as $result){
            $name = $result['name'] ? $result['name'] : 'IVR ' . $result['id'];
            $extens[] = array('destination' => 'ivr-'.$result['id'].',s,1', 'description' => $name);
        }
    }
    if (isset($extens)) {
        return $extens;
    } else {
        return null;
    }

}

//dialplan generator
function ivr_get_config($engine) {
    global $ext, $amp_conf;

    $show_spoken=0;
    $engineinfo = engine_getinfo();
    $astver =  $engineinfo['version'];
    $ast_ge_1616 = version_compare($astver, '16.16', 'ge');
    if(file_exists("/etc/asterisk/res-speech-vosk.conf") && $ast_ge_1616) {
        $show_spoken=1;
    }

    switch($engine) {
        case "asterisk":
            $ddial_contexts = array();
            $ivrlist = ivr_get_details();
            if(!is_array($ivrlist)) {
                break;
            }

            if (function_exists('queues_list')) {
                //draw a list of ivrs included by any queues
                $queues = queues_list(true);
                $qivr = array();
                foreach ($queues as $q) {
                    $thisq = queues_get($q[0]);
                    if ($thisq['context'] && strpos($thisq['context'], 'ivr-') === 0) {
                        $qivr[] = str_replace('ivr-', '', $thisq['context']);
                    }
                }
            }

            foreach($ivrlist as $ivr) {
                $c = 'ivr-' . $ivr['id'];
                $ivr = ivr_get_details($ivr['id']);
                $ext->addSectionComment($c, $ivr['name'] ? $ivr['name'] : 'IVR ' . $ivr['id']);

                if ($ivr['directdial']) {
                    if ($ivr['directdial'] == 'ext-local') {
                        $ext->addInclude($c, 'from-did-direct-ivr'); //generated in core module
                    } else {
                        //generated by directory
                        $ext->addInclude($c, 'from-ivr-directory-' . $ivr['directdial']);
                        $directdial_contexts[$ivr['directdial']] = $ivr['directdial'];
                    }
                }

                //set variables for loops when used
                if ($ivr['timeout_loops'] != 'disabled' && $ivr['timeout_loops'] > 0) {
                    $ext->add($c, 's', '', new ext_setvar('TIMEOUT_LOOPCOUNT', 0));
                }
                if ($ivr['invalid_loops'] != 'disabled' && $ivr['invalid_loops'] > 0) {
                    $ext->add($c, 's', '', new ext_setvar('INVALID_LOOPCOUNT', 0));
                }

                $ext->add($c, 's', '', new ext_setvar('_IVR_CONTEXT_${CONTEXT}', '${IVR_CONTEXT}'));
                $ext->add($c, 's', '', new ext_setvar('_IVR_CONTEXT', '${CONTEXT}'));
                if ($ivr['retvm']) {
                    $ext->add($c, 's', '', new ext_setvar('__IVR_RETVM', 'RETURN'));
                } else {
                    //TODO: do we need to set anything at all?
                    $ext->add($c, 's', '', new ext_setvar('__IVR_RETVM', ''));
                }

                $ext->add($c, 's', '', new ext_gotoif('$["${CDR(disposition)}" = "ANSWERED"]','skip'));
                $ext->add($c, 's', '', new ext_gotoif('$["${CDR(answer)}" != ""]','skip'));
                $ext->add($c, 's', '', new ext_answer(''));
                $ext->add($c, 's', '', new ext_wait('1'));

                $ivr_announcement = recordings_get_file($ivr['announcement']);
                $ext->add($c, 's', 'skip', new ext_set('IVR_MSG', $ivr_announcement));

                $ext->add($c, 's', 'start', new ext_digittimeout(3));
                //$ext->add($ivr_id, 's', '', new ext_responsetimeout($ivr['timeout_time']));

                $entries = ivr_get_entries($ivr['id']);

                $do_spoken_dialplan=0;

                if ($entries) {
                    if($show_spoken==1) {
                        foreach($entries as $e) {
                            if($e['spoken']!='') {
                                $do_spoken_dialplan=1;
                            }
                        }
                    }
                }

                if($do_spoken_dialplan==0) {
                    $ext->add($c, 's', '', new ext_execif('$["${IVR_MSG}" != ""]','Background','${IVR_MSG}'));
                    $ext->add($c, 's', '', new ext_waitexten($ivr['timeout_time']));
                } else {
                    $ext->add($c, 's', '', new ext_speechcreate());

                    // Grammar
                    if(isset($amp_conf["IVR_USE_VOSK_GRAMMAR"]) && $amp_conf["IVR_USE_VOSK_GRAMMAR"]==1) {
                        $allwords=array();
                        if ($entries) {
                            foreach($entries as $e) {
                                if($e['spoken']!='') {
                                    $allwords = array_merge($allwords,preg_split("/,/",$e['spoken']));
                                }
                            }
                            $word_list = '"'.implode('","',$allwords).'"';
                            $ext->add($c, 's', '', new ext_speechactivategrammar($word_list));
                        }
                    }

                    $ext->add($c, 's', '', new ext_gotoif('$["${ERROR}" = "1"]','skipspeech'));
                    $ext->add($c, 's', '', new ext_execif('$["${IVR_MSG}" != ""]','SpeechBackground','${IVR_MSG},'.$ivr['timeout_time']));
                    $ext->add($c, 's', '', new ext_execif('$["${IVR_MSG}X" = "X"]','SpeechBackground','silence/5,'.$ivr['timeout_time']));
                    $ext->add($c, 's', '', new ext_setvar('RESULT', '${SPEECH_TEXT(0)}'));

                }

                // Actually add the IVR entires now
                if ($entries) {
                    // pass for spoken text
                    if($do_spoken_dialplan==1) {
                        foreach($entries as $e) {
                            if($e['spoken']!='') {
                                $words = preg_split("/,/",$e['spoken']);
                                foreach($words as $word) {
                                    $ext->add($c, 's', '', new ext_execif('$["${RESULT}" =~ "'.$word.'"]','Set','RESULT='.$e['selection']));
                                }
                            }
                        }
                        $ext->add($c, 's', '', new ext_execif('$["${RESULT}x" = "x"]','Set','RESULT=t'));
                        $ext->add($c, 's', '', new ext_goto('${RESULT},1'));
                        $ext->add($c, 's', 'skipspeech', new ext_execif('$["${IVR_MSG}" != ""]','Background','${IVR_MSG}'));
                        $ext->add($c, 's', '', new ext_waitexten($ivr['timeout_time']));
                    }

                    foreach($entries as $e) {
                        //dont set a t or i if there already defined above
                        if ($e['selection'] == 't' && $ivr['timeout_loops'] != 'disabled') {
                             continue;
                        }
                        if ($e['selection'] == 'i' && $ivr['invalid_loops'] != 'disabled') {
                             continue;
                        }

                        //only display these two lines if the ivr is included in any queues
                        if (function_exists('queues_list') && in_array($ivr['id'], $qivr)) {
                            $ext->add($c, $e['selection'],'', new ext_macro('blkvm-clr'));
                            $ext->add($c, $e['selection'], '', new ext_setvar('__NODEST', ''));
                        }

                        if ($e['ivr_ret']) {
                            $ext->add($c, $e['selection'], '',
                                new ext_gotoif('$["x${IVR_CONTEXT_${CONTEXT}}" = "x"]',
                                    $e['dest'] . ':${IVR_CONTEXT_${CONTEXT}},return,1'));
                        } else {
                            $ext->add($c, $e['selection'], '', new ext_setvar('__IVR_DIGIT_PRESSED', '${EXTEN}'));
                            $ext->add($c, $e['selection'],'ivrsel-' . $e['selection'], new ext_goto($e['dest']));
                        }
                    }
                }

                // add invalid destination if required
                if ($ivr['invalid_loops'] != 'disabled') {
                    if ($ivr['invalid_loops'] > 0) {
                        $ext->add($c, 'i', '', new ext_set('INVALID_LOOPCOUNT', '$[${INVALID_LOOPCOUNT}+1]'));
                        $ext->add($c, 'i', '',    new ext_gotoif('$[${INVALID_LOOPCOUNT} > ' . $ivr['invalid_loops'] . ']','final'));
                        switch ($ivr['invalid_retry_recording']) {
                            case 'default':
                                $invalid_annoucement = 'no-valid-responce-pls-try-again';
                                break;
                            case '':
                                $invalid_annoucement = '';
                                break;
                            default:
                                $invalid_annoucement = recordings_get_file($ivr['invalid_retry_recording']);
                                break;
                        }

                        if ($ivr['invalid_append_announce'] || $invalid_annoucement == '') {
                            $invalid_annoucement .= '&' . $ivr_announcement;
                        }
                        $ext->add($c, 'i', '', new ext_set('IVR_MSG', trim($invalid_annoucement, '&')));
                        $ext->add($c, 'i', '', new ext_goto('s,start'));
                    }

                    $label = 'final';
                    switch ($ivr['invalid_recording']) {
                        case 'default':
                            $ext->add($c, 'i', $label, new ext_playback('no-valid-responce-transfering'));
                            $label ='';
                            break;
                        case '':
                            break;
                        default:
                            $ext->add($c, 'i', $label,
                                new ext_playback(recordings_get_file($ivr['invalid_recording'])));
                            $label = '';
                            break;
                    }
                    if (!empty($ivr['invalid_ivr_ret'])) {
                        $ext->add($c, 'i', $label,
                            new ext_gotoif('$["x${IVR_CONTEXT_${CONTEXT}}" = "x"]',
                                $ivr['invalid_destination'] . ':${IVR_CONTEXT_${CONTEXT}},return,1'));
                    } else {
                        $ext->add($c, 'i', $label, new ext_goto($ivr['invalid_destination']));
                    }
                } else {
                    // If no invalid destination provided we need to do something
                    $ext->add($c, 'i', '', new ext_playback('sorry-youre-having-problems'));
                    $ext->add($c, 'i', '', new ext_goto('1','hang'));
                }

                // Apply timeout destination if required
                if ($ivr['timeout_loops'] != 'disabled') {
                    if ($ivr['timeout_loops'] > 0) {
                        $ext->add($c, 't', '', new ext_set('TIMEOUT_LOOPCOUNT', '$[${TIMEOUT_LOOPCOUNT}+1]'));
                        $ext->add($c, 't', '', new ext_gotoif('$[${TIMEOUT_LOOPCOUNT} > ' . $ivr['timeout_loops'] . ']','final'));

                        switch ($ivr['timeout_retry_recording']) {
                            case 'default':
                                $timeout_annoucement = 'no-valid-responce-pls-try-again';
                                break;
                            case '':
                                $timeout_annoucement = '';
                                break;
                            default:
                                $timeout_annoucement = recordings_get_file($ivr['timeout_retry_recording']);
                                break;
                        }

                        if ($ivr['timeout_append_announce'] || $timeout_annoucement == '') {
                            $timeout_annoucement .= '&' . $ivr_announcement;
                        }
                        $ext->add($c, 't', '', new ext_set('IVR_MSG', trim($timeout_annoucement, '&')));
                        $ext->add($c, 't', '', new ext_goto('s,start'));
                    }

                    $label = 'final';
                    switch ($ivr['timeout_recording']) {
                        case 'default':
                            $ext->add($c, 't', $label, new ext_playback('no-valid-responce-transfering'));
                            $label = '';
                            break;
                        case '':
                            break;
                        default:
                            $ext->add($c, 't', $label,
                                new ext_playback(recordings_get_file($ivr['timeout_recording'])));
                            $label = '';
                            break;
                    }
                    if (!empty($ivr['timeout_ivr_ret'])) {
                        $ext->add($c, 't', $label,
                            new ext_gotoif('$["x${IVR_CONTEXT_${CONTEXT}}" = "x"]',
                                $ivr['timeout_destination'] . ':${IVR_CONTEXT_${CONTEXT}},return,1'));
                    } else {
                        $ext->add($c, 't', $label, new ext_goto($ivr['timeout_destination']));
                    }
                } else {
                    // If no invalid destination provided we need to do something
                    $ext->add($c, 't', '', new ext_playback('sorry-youre-having-problems'));
                    $ext->add($c, 't', '', new ext_goto('1','hang'));
                }

                // these need to be reset or inheritance problems makes them go away in some conditions
                //and infinite inheritance creates other problems
                $ext->add($c, 'return', '', new ext_setvar('_IVR_CONTEXT', '${CONTEXT}'));
                $ext->add($c, 'return', '', new ext_setvar('_IVR_CONTEXT_${CONTEXT}', '${IVR_CONTEXT_${CONTEXT}}'));
                $ext->add($c, 'return', '', new ext_set('IVR_MSG', $ivr_announcement));
                $ext->add($c, 'return', '', new ext_goto('s,start'));

                //h extension
                $ext->add($c, 'h', '', new ext_hangup(''));
                $ext->add($c, 'hang', '', new ext_playback('vm-goodbye'));
                $ext->add($c, 'hang', '', new ext_hangup(''));
            }


            //generate from-ivr-directory contexts for direct dialing a directory entire
            if (!empty($directdial_contexts)) {
                foreach($directdial_contexts as $dir_id) {
                    $c = 'from-ivr-directory-' . $dir_id;
                    $entries = function_exists('directory_get_dir_entries') ? directory_get_dir_entries($dir_id) : array();
                    foreach ($entries as $dstring) {
                        $exten = $dstring['dial'] == '' ? $dstring['foreign_id'] : $dstring['dial'];
                        if ($exten == '' || $exten == 'custom') {
                            continue;
                        }
                        $ext->add($c, $exten, '', new ext_macro('blkvm-clr'));
                        $ext->add($c, $exten, '', new ext_setvar('__NODEST', ''));
                        $ext->add($c, $exten, '', new ext_goto('1', $exten, 'from-internal'));
                    }
                }
            }
        break;
    }
}

//replaces ivr_list(), returns all details of any ivr
function ivr_get_details($id = '') {
    global $db;

    $sql = "SELECT `spoken` FROM ivr_entries";
    $check = $db->getRow($sql, DB_FETCHMODE_ASSOC);
    if(DB::IsError($check)) {
        $sql = "ALTER TABLE ivr_entries ADD spoken varchar(200) not null default ''";
        $result = $db->query($sql);
    }

    $sql = "SELECT *, announcement announcement_id FROM ivr_details";
    if ($id) {
        $sql .= ' where  id = "' . $id . '"';
    } else {
        // Corrado Mella, 06/02/2014 - IVR list in alphabetical order
        $sql .= ' ORDER BY name';
        // Corrado Mella
    }

    $res = $db->getAll($sql, DB_FETCHMODE_ASSOC);
    if($db->IsError($res)) {
        die_issabelpbx($res->getDebugInfo());
    }

    return $id && isset($res[0]) ? $res[0] : $res;
}

//get all ivr entires
function ivr_get_entries($id) {
    global $db;

    //+0 to convert string to an integer
    $sql = "SELECT * FROM ivr_entries WHERE ivr_id = ? ORDER BY selection + 0 DESC";
    $res = $db->getAll($sql, array($id), DB_FETCHMODE_ASSOC);
    if ($db->IsError($res)) {
        die_issabelpbx($res->getDebugInfo());
    }
    return $res;
}


//draw ivr options page
function ivr_configpageload() {
    global $currentcomponent, $display;
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
    $id     = isset($_REQUEST['extdisplay']) ? $_REQUEST['extdisplay'] : null;

    if ($action  == 'add' || ($action == '' && $id == null)) {
        $currentcomponent->addguielem('_top', new gui_pageheading('title', __('Add IVR')), 0);

        $deet = array('id', 'name', 'description', 'announcement', 'directdial',
                    'invalid_loops', 'invalid_retry_recording',
                    'invalid_recording', 'invalid_destination', 'invalid_ivr_ret',
                    'timeout_loops', 'timeout_time', 'timeout_retry_recording',
                    'timeout_recording', 'timeout_destination', 'timeout_ivr_ret',
                    'retvm');

        //keep vairables set on new ivr's
        foreach ($deet as $d) {
            switch ($d){
                case 'invalid_loops':
                case 'timeout_loops';
                    $ivr[$d] = 3;
                    break;
                case 'announcement':
                    $ivr[$d] = '';
                    break;
                case 'invalid_recording':
                case 'invalid_retry_recording':
                case 'timeout_retry_recording':
                case 'timeout_recording':
                    $ivr[$d] = 'default';
                    break;
                case 'timeout_time':
                    $ivr[$d] = 10;
                    break;
                default:
                $ivr[$d] = '';
                    break;
            }
        }
    } else {
        $ivr = ivr_get_details($id);

        $label = sprintf(__("Edit IVR: %s"), $ivr['name'] ? $ivr['name'] : 'ID '.$ivr['id']);
        $currentcomponent->addguielem('_top', new gui_pageheading('title', $label), 0);

        //display usage
        $usage_list            = framework_display_destination_usage(ivr_getdest($ivr['id']));
        if (!empty($usage_list)) {
            $usage_list_text    = isset($usage_list['text']) ? $usage_list['text'] : '';
            $usage_list_tooltip    = isset($usage_list['tooltip']) ? $usage_list['tooltip'] : '';
            $currentcomponent->addguielem('_top',
                new gui_link_label('usage', $usage_list_text, $usage_list_tooltip), 0);
        }

    }

    //general options
    $gen_section = __('IVR General Options');
    $currentcomponent->addguielem($gen_section,
        new gui_textbox('name', stripslashes($ivr['name']), __('IVR Name'), __('Name of this IVR.')));
    $currentcomponent->addguielem($gen_section,
        new gui_textbox('description', stripslashes($ivr['description']),
        __('IVR Description'), __('Description of this ivr.')));


    //dtmf options
    $section = __('IVR Options (DTMF)');

    //build recordings select list
    $currentcomponent->addoptlistitem('recordings', '', __('None'));
    foreach(recordings_list() as $r){
        $currentcomponent->addoptlistitem('recordings', $r['id'], $r['displayname']);
    }
    $currentcomponent->setoptlistopts('recordings', 'sort', false);

    //build repeat_loops select list and defualt it to 3
    //while addoptlist is not usually required, declaring this is the only way to prevent sorting on the list
    $currentcomponent->addoptlist('ivr_repeat_loops', false);
    $currentcomponent->addoptlistitem('ivr_repeat_loops', 'disabled', __('Disabled'));
    for($i=0; $i <11; $i++){
        $currentcomponent->addoptlistitem('ivr_repeat_loops', $i, $i);
    }

    //greating to be played on entry to the ivr
    $currentcomponent->addguielem($section,
        new gui_selectbox('announcement', $currentcomponent->getoptlist('recordings'),
            $ivr['announcement'], __('Announcement'), __('Greeting to be played on entry to the Ivr.'), false));



    //direct dial
    $currentcomponent->addoptlistitem('directdial', '', __('Disabled'));
    $currentcomponent->addoptlistitem('directdial', 'ext-local', __('Extensions'));

    $currentcomponent->addgeneralarrayitem('directdial_help', 'disabled', __('Completely disabled'));
    $currentcomponent->addgeneralarrayitem('directdial_help', 'local', __('Enabled for all extensions on a system'));

    $currentcomponent->addguielem($section,
        new gui_selectbox('directdial', $currentcomponent->getoptlist('directdial'),
        $ivr['directdial'], __('Direct Dial'), __('Provides options for callers to direct dial an extension. Direct dialing can be:')
        . ul($currentcomponent->getgeneralarray('directdial_help')), false));

    //add default to the recordings list. We dont want this for the general announcement, so we do it here
    $currentcomponent->addoptlistitem('recordings', 'default', __('Default'));
    //$currentcomponent->addguielem($section, new gui_textbox('timeout_time', stripslashes($ivr['timeout_time']), __('Timeout'), __('Amount of time to be concidered a timeout')));
    $currentcomponent->addguielem($section, new guielement('timeout_time',
        '<tr class="IVROptionsDTMF"><td>' . ipbx_label(__('Timeout'), __('Amount of time to be considered a timeout')).'</td><td><input type="number" name="timeout_time" value="'
                    . $ivr['timeout_time']
                    .'" required class="w100 input"></td></tr>'));
    //invalid
    $currentcomponent->addguielem($section,
        new gui_selectbox('invalid_loops', $currentcomponent->getoptlist('ivr_repeat_loops'),
        $ivr['invalid_loops'], __('Invalid Retries'), __('Number of times to retry when receiving an invalid/unmatched response from the caller'), false));
    $currentcomponent->addguielem($section,
        new gui_selectbox('invalid_retry_recording', $currentcomponent->getoptlist('recordings'),
        $ivr['invalid_retry_recording'], __('Invalid Retry Recording'), __('Prompt to be played when an invalid/unmatched response is received, before prompting the caller to try again'), false));

    if(!isset($ivr['invalid_append_announce'])) $ivr['invalid_append_announce']=0;

    $currentcomponent->addguielem($section,
        new gui_switch('invalid_append_announce', $ivr['invalid_append_announce'], __('Append Announcement on Invalid'), __('After playing the Invalid Retry Recording the system will replay the main IVR Announcement')));

    $currentcomponent->addguielem($section,
        new gui_switch('invalid_ivr_ret', $ivr['invalid_ivr_ret'], __('Return on Invalid'), __('Check this box to have this option return to a parent IVR if it was called '
            . 'from a parent IVR. If not, it will go to the chosen destination.<br><br>'
            . 'The return path will be to any IVR that was in the call path prior to this '
            . 'IVR which could lead to strange results if there was an IVR called in the '
            . 'call path but not immediately before this')));

    $currentcomponent->addguielem($section,
        new gui_selectbox('invalid_recording', $currentcomponent->getoptlist('recordings'),
        $ivr['invalid_recording'], __('Invalid Recording'), __('Prompt to be played before sending the caller to an alternate destination due to the caller pressing 0 or receiving the maximum amount of invalid/unmatched responses (as determined by Invalid Retries)'), false));
    $currentcomponent->addguielem($section,
        new gui_drawselects('invalid_destination', 'invalid', $ivr['invalid_destination'], __('Invalid Destination'),
         __('Destination to send the call to after Invalid Recording is played.'), true));

    //timeout
    $currentcomponent->addguielem($section,
        new gui_selectbox('timeout_loops', $currentcomponent->getoptlist('ivr_repeat_loops'),
        $ivr['timeout_loops'], __('Timeout Retries'), __('Number of times to retry when no DTMF is heard and the IVR choice times out.'), false));
    $currentcomponent->addguielem($section,
        new gui_selectbox('timeout_retry_recording', $currentcomponent->getoptlist('recordings'),
        $ivr['timeout_retry_recording'], __('Timeout Retry Recording'), __('Prompt to be played when a timeout occurs, before prompting the caller to try again'), false));

    if(!isset($ivr['timeout_append_announce'])) $ivr['timeout_append_announce']=0;
    $currentcomponent->addguielem($section,
        new gui_switch('timeout_append_announce', $ivr['timeout_append_announce'], __('Append Announcement on Timeout'), __('After playing the Timeout Retry Recording the system will replay the main IVR Announcement')));

    $currentcomponent->addguielem($section,
        new gui_switch('timeout_ivr_ret', $ivr['timeout_ivr_ret'], __('Return on Timeout'), __('Check this box to have this option return to a parent IVR if it was called '
            . 'from a parent IVR. If not, it will go to the chosen destination.<br><br>'
            . 'The return path will be to any IVR that was in the call path prior to this '
            . 'IVR which could lead to strange results if there was an IVR called in the '
            . 'call path but not immediately before this')));

    $currentcomponent->addguielem($section,
        new gui_selectbox('timeout_recording', $currentcomponent->getoptlist('recordings'),
        $ivr['timeout_recording'], __('Timeout Recording'), __('Prompt to be played before sending the caller to an alternate destination due to the caller pressing 0 or receiving the maximum amount of invalid/unmatched responses (as determined by Invalid Retries)'), false));
    $currentcomponent->addguielem($section,
        new gui_drawselects('timeout_destination', 'timeout',
        $ivr['timeout_destination'], __('Timeout Destination'), __('Destination to send the call to after Timeout Recording is played.'), true));

    //return to ivr
    $currentcomponent->addguielem($section,
        new gui_switch('retvm', $ivr['retvm'], __('Return to IVR after VM'), __('If checked, upon exiting voicemail a caller will be returned to this IVR if they got a users voicemail')));

    /*$currentcomponent->addguielem($section,
        new gui_switch('say_extension', $dir['say_extension'], __('Announce Extension'),
        __('When checked, the extension number being transferred to will be announced prior to the transfer'),true));*/
    $currentcomponent->addguielem($section, new gui_hidden('extdisplay', $ivr['id']));
    $currentcomponent->addguielem($section, new gui_hidden('action', 'save'));

    $section = __('IVR Entries');
    //draw the entries part of the table. A bit hacky perhaps, but hey - it works!
    $currentcomponent->addguielem($section, new guielement('rawhtml', ivr_draw_entries($ivr['id']), ''), 6);
}

function ivr_configpageinit($pagename) {
    global $currentcomponent;
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
    $extdisplay = isset($_REQUEST['extdisplay']) ? $_REQUEST['extdisplay'] : '';

    if($pagename == 'ivr'){
        $currentcomponent->addprocessfunc('ivr_configprocess');

        //dont show page if there is no action set
//        if ($action && $action != 'delete' || $extdisplay) {
            $currentcomponent->addguifunc('ivr_configpageload');
//        }
// nico
    return true;
    }
}

//prosses received arguments
function ivr_configprocess(){
    if (isset($_REQUEST['display']) && $_REQUEST['display'] == 'ivr'){
        global $db;
        //get variables

        $get_var = array('extdisplay', 'name', 'description', 'announcement',
                        'directdial', 'invalid_loops', 'invalid_retry_recording',
                        'invalid_destination', 'invalid_recording',
                        'retvm', 'timeout_time', 'timeout_recording',
                        'timeout_retry_recording', 'timeout_destination', 'timeout_loops',
                        'timeout_append_announce', 'invalid_append_announce',
                        'timeout_ivr_ret', 'invalid_ivr_ret');
        foreach($get_var as $var){
            $vars[$var] = isset($_REQUEST[$var])     ? $_REQUEST[$var]        : '';
        }
        $vars['timeout_append_announce'] = empty($vars['timeout_append_announce']) ? '0' : '1';
        $vars['invalid_append_announce'] = empty($vars['invalid_append_announce']) ? '0' : '1';
        $vars['timeout_ivr_ret'] = empty($vars['timeout_ivr_ret']) ? '0' : '1';
        $vars['invalid_ivr_ret'] = empty($vars['invalid_ivr_ret']) ? '0' : '1';
        $vars['announcement']=intval($vars['announcement']);

        $action        = isset($_REQUEST['action'])    ? $_REQUEST['action']    : '';
        $entries    = isset($_REQUEST['entries'])    ? $_REQUEST['entries']    : '';

        switch ($action) {
            case 'save':
                //get real dest
                $vars['extdisplay'] = $vars['id'] = ivr_save_details($vars);
                ivr_save_entries($vars['extdisplay'], $entries);
                needreload();
                //$_REQUEST['action'] = 'edit';
                $this_dest = ivr_getdest($vars['extdisplay']);
                fwmsg::set_dest($this_dest[0]);
                $_SESSION['msg']=base64_encode(_dgettext('amp','Item has been saved'));
                $_SESSION['msgtype']='success';
                $_SESSION['msgtstamp']=time();
                redirect_standard_continue('extdisplay');
            break;
            case 'delete':
                ivr_delete($vars['extdisplay']);
                needreload();
                $_SESSION['msg']=base64_encode(_dgettext('amp','Item has been deleted'));
                $_SESSION['msgtype']='warning';
                $_SESSION['msgtstamp']=time();
                redirect_standard_continue();
            break;
        }
    }
}

//save ivr settings
function ivr_save_details($vals){
    global $db, $amp_conf;

    foreach($vals as $key => $value) {
        $vals[$key] = $db->escapeSimple($value);
    }

    if ($vals['extdisplay']) {
        $sql = 'REPLACE INTO ivr_details (id, name, description, announcement,
                directdial, invalid_loops, invalid_retry_recording,
                invalid_destination, invalid_recording,
                retvm, timeout_time, timeout_recording,
                timeout_retry_recording, timeout_destination, timeout_loops,
                timeout_append_announce, invalid_append_announce, timeout_ivr_ret, invalid_ivr_ret)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $foo = $db->query($sql, $vals);
        if($db->IsError($foo)) {
            die_issabelpbx(print_r($vals,true).' '.$foo->getDebugInfo());
        }
    } else {
        unset($vals['extdisplay']);
        $sql = 'INSERT INTO ivr_details (name, description, announcement,
                directdial, invalid_loops, invalid_retry_recording,
                invalid_destination,  invalid_recording,
                retvm, timeout_time, timeout_recording,
                timeout_retry_recording, timeout_destination, timeout_loops,
                timeout_append_announce, invalid_append_announce, timeout_ivr_ret, invalid_ivr_ret)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $foo = $db->query($sql, $vals);
        if($db->IsError($foo)) {
            die_issabelpbx(print_r($vals,true).' '.$foo->getDebugInfo());
        }
        $sql = ( (preg_match("/qlite/",$amp_conf["AMPDBENGINE"])) ? 'SELECT last_insert_rowid()' : 'SELECT LAST_INSERT_ID()');
        $vals['extdisplay'] = $db->getOne($sql);
        if ($db->IsError($foo)){
            die_issabelpbx($foo->getDebugInfo());
        }
    }

    return $vals['extdisplay'];
}

//save ivr entires
function ivr_save_entries($id, $entries){
    global $db;
    $id = $db->escapeSimple($id);
    sql('DELETE FROM ivr_entries WHERE ivr_id = "' . $id . '"');

    if ($entries) {
        for ($i = 0; $i < count($entries['ext']); $i++) {
            //make sure there is an extension & goto set - otherwise SKIP IT
            if (trim($entries['ext'][$i]) != '' && $entries['goto'][$i]) {
                $d[] = array(
                            'ivr_id'    => $id,
                            'selection'     => $entries['ext'][$i],
                            'dest'        => $entries['goto'][$i],
                            'ivr_ret'    => (isset($entries['ivr_ret'][$i]) ? intval($entries['ivr_ret'][$i]) : ''),
                            'spoken'    => isset($entries['spoken'][$i])?$entries['spoken'][$i]:''
                        );
            }

        }
        $sql = $db->prepare('INSERT INTO ivr_entries VALUES (?, ?, ?, ?, ?)');
        $res = $db->executeMultiple($sql, $d);
        if ($db->IsError($res)){
            die_issabelpbx($res->getDebugInfo());
        }
    }

    return true;
}

//draw uvr entires table header
function ivr_draw_entries_table_header_ivr() {

    $show_spoken=0;
    $engineinfo = engine_getinfo();
    $astver     = $engineinfo['version'];
    $ast_ge_1616 = version_compare($astver, '16.16', 'ge');
    if(file_exists("/etc/asterisk/res-speech-vosk.conf") && $ast_ge_1616) {
        $show_spoken=1;
    }

    $headers = array();
    $headers[] = ipbx_label(__('Ext'),__('Any digit selection will be saved in the IVR_DIGIT_PRESSED chanel variable'));
    $headers[] = __('Destination');
    $headers[] = ipbx_label(__('Return'), __('Return to IVR'));

    if($show_spoken==1) {
        $headers[] = __('Spoken');
    }
    $headers[] = __('Delete');

    return $headers;
}

//draw actualy entires
function ivr_draw_entries($id){
    $headers        = mod_func_iterator('draw_entries_table_header_ivr');
    $ivr_entries    = ivr_get_entries($id);

    if ($ivr_entries) {
        foreach ($ivr_entries as $k => $e) {
            $entries[$k]= $e;
            $array = array('id' => $id, 'ext' => $e['selection']);
            $entries[$k]['hooks'] = mod_func_iterator('draw_entries_ivr', $array);
        }
    }

    $entries['blank'] = array('selection' => '', 'dest' => '', 'ivr_ret' => '', 'spoken'=>'');
    //assign to a vatriable first so that it can be passed by reference
    $array = array('id' => '', 'ext' => '');
    $entries['blank']['hooks'] = mod_func_iterator('draw_entries_ivr', $array);

    return load_view(dirname(__FILE__) . '/views/entries.php',
                array(
                    'headers'    => $headers,
                    'entries'    =>  $entries
                )
            );

}

//delete an ivr + entires
function ivr_delete($id) {
    global $db;
    sql('DELETE FROM ivr_details WHERE id = "' . $db->escapeSimple($id) . '"');
    sql('DELETE FROM ivr_entries WHERE ivr_id = "' . $db->escapeSimple($id) . '"');
}
//----------------------------------------------------------------------------
// Dynamic Destination Registry and Recordings Registry Functions
function ivr_check_destinations($dest=true) {
    global $active_modules;

    $destlist = array();
    if (is_array($dest) && empty($dest)) {
        return $destlist;
    }
    $sql = "SELECT dest, name, selection, a.id id FROM ivr_details a INNER JOIN ivr_entries d ON a.id = d.ivr_id  ";
    if ($dest !== true) {
        $sql .= "WHERE dest in ('".implode("','",$dest)."')";
    }
    $sql .= "ORDER BY name";
    $results = sql($sql,"getAll",DB_FETCHMODE_ASSOC);

    foreach ($results as $result) {
        $thisdest = $result['dest'];
        $thisid   = $result['id'];
        $name = $result['name'] ? $result['name'] : 'IVR ' . $thisid;
        $destlist[] = array(
            'dest' => $thisdest,
            'description' => sprintf(__("IVR: %s / Option: %s"),$name,$result['selection']),
            'edit_url' => 'config.php?display=ivr&action=edit&extdisplay='.urlencode($thisid),
        );
    }
    return $destlist;
}



function ivr_change_destination($old_dest, $new_dest) {
    global $db;
     $sql = "UPDATE ivr_entires SET dest = '$new_dest' WHERE dest = '$old_dest'";
     $db->query($sql);

}


function ivr_getdest($exten) {
    return array('ivr-'.$exten.',s,1');
}

function ivr_getdestinfo($dest) {
    global $active_modules;

    if (substr(trim($dest),0,4) == 'ivr-') {
        $exten = explode(',',$dest);
        $exten = substr($exten[0],4);

        $thisexten = ivr_get_details($exten);
        if (empty($thisexten)) {
            return array();
        } else {
            //$type = isset($active_modules['ivr']['type'])?$active_modules['ivr']['type']:'setup';
            return array('description' => sprintf(__("IVR: %s"), ($thisexten['name'] ? $thisexten['name'] : $thisexten['id'])),
                         'edit_url' => 'config.php?display=ivr&action=edit&extdisplay='.urlencode($exten),
                                  );
        }
    } else {
        return false;
    }
}

function ivr_recordings_usage($recording_id) {
    global $active_modules;

    $sql = "SELECT `id`, `name` FROM `ivr_details` WHERE `announcement` = '$recording_id' OR `invalid_retry_recording` = '$recording_id' OR `invalid_recording` = '$recording_id' OR `timeout_recording` = '$recording_id' OR `timeout_retry_recording` = '$recording_id'";
    $results = sql($sql, "getAll",DB_FETCHMODE_ASSOC);
    if (empty($results)) {
        return array();
    } else {
        foreach ($results as $result) {
            $usage_arr[] = array(
                'url_query' => 'config.php?display=ivr&action=edit&extdisplay='.urlencode($result['id']),
                'description' => sprintf(__("IVR: %s"), ($result['name'] ? $result['name'] : $result['id'])),
            );
        }
        return $usage_arr;
    }
}

?>
