<?php

class acymcronHelper extends acymObject
{
    var $report = false;
    var $messages = [];
    var $detailMessages = [];

    // Did the cron process anything?
    var $processed = false;

    // Has the cron system been executed or did we block it?
    var $executed = false;

    // Save the main message
    var $mainmessage = '';

    // Is there any error in the process
    var $errorDetected = false;

    // Process we should skip... bounce,send,filters,schedule,autonews,plugins
    var $skip = [];

    // Type of emails we should send... news, followup
    var $emailtypes = [];

    public function cron()
    {
        // Step 1: Check the last cron launched...
        $time = time();

        $firstMessage = acym_translation_sprintf('ACYM_CRON_TRIGGERED', acym_date('now', 'd F Y H:i'));
        $this->messages[] = $firstMessage;
        if ($this->report) {
            acym_display($firstMessage, 'info');
        }

        if ($this->config->get('cron_next') > $time) {
            if ($this->config->get('cron_next') > ($time + $this->config->get('cron_frequency'))) {
                //There is something wrong here... so we put back the normal time
                $newConfig = new stdClass();
                $newConfig->cron_next = $time + $this->config->get('cron_frequency');
                $this->config->save($newConfig);
            }

            $nottime = acym_translation_sprintf('ACYM_CRON_NEXT', acym_date($this->config->get('cron_next'), 'd F Y H:i'));
            $this->messages[] = $nottime;
            if ($this->report) {
                //We dont need to trigger anything, it's not time to do it.
                acym_display($nottime, 'info');
            }

            return false;
        }

        // We should trigger the cron now...
        $this->executed = true;


        // Step 2: we update the next cron and the last cron dates
        $newConfig = new stdClass();
        $newConfig->cron_last = $time;
        $newConfig->cron_fromip = acym_getIP();
        $newConfig->cron_next = $this->config->get('cron_next') + $this->config->get('cron_frequency');

        //We update the next cron properly
        if ($newConfig->cron_next <= $time || $newConfig->cron_next > $time + $this->config->get('cron_frequency')) {
            $newConfig->cron_next = $time + $this->config->get('cron_frequency');
        }

        $this->config->save($newConfig);


        // Step 3: Enqueue the scheduled campaigns
        if (!in_array('schedule', $this->skip)) {
            $queueClass = acym_get('class.queue');
            $nbScheduled = $queueClass->scheduleReady();
            if ($nbScheduled) {
                $this->messages[] = acym_translation_sprintf('ACYM_NB_SCHEDULED', $nbScheduled);
                $this->detailMessages = array_merge($this->detailMessages, $queueClass->messages);
                $this->processed = true;
            }
        }

        // Step 4: Clean the queue
        if (!in_array('cleanqueue', $this->skip)) {
            $deletedNb = $queueClass->cleanQueue();

            if (!empty($deletedNb)) {
                $this->messages[] = acym_translation_sprintf('ACYM_EMAILS_REMOVED_QUEUE_CLEAN', $deletedNb);
                $this->processed = true;
            }
        }

        // Step 5: We send the queued emails that are ready
        if ($this->config->get('queue_type') != 'manual' && !in_array('send', $this->skip)) {
            $queueHelper = acym_get('helper.queue');
            $queueHelper->send_limit = (int)$this->config->get('queue_nbmail_auto');
            $queueHelper->report = false;
            $queueHelper->emailtypes = $this->emailtypes;
            $queueHelper->process();
            if (!empty($queueHelper->messages)) {
                $this->detailMessages = array_merge($this->detailMessages, $queueHelper->messages);
            }
            if (!empty($queueHelper->nbprocess)) {
                $this->processed = true;
            }
            $this->mainmessage = acym_translation_sprintf('ACYM_CRON_PROCESS', $queueHelper->nbprocess, $queueHelper->successSend, $queueHelper->errorSend);
            $this->messages[] = $this->mainmessage;

            if (!empty($queueHelper->errorSend)) {
                $this->errorDetected = true;
            }
            //Check on the time limitation so we stop the process if we reached it
            if (!empty($queueHelper->stoptime) && time() > $queueHelper->stoptime) {
                return true;
            }
        }

        // Step 6: run automatic bounce handling!
        if (!in_array('bounce', $this->skip) && acym_level(2) && $this->config->get('auto_bounce', 0) && $time > (int)$this->config->get('auto_bounce_next', 0) && (empty($queueHelper->stoptime) || time() < $queueHelper->stoptime - 5)) {

            //First we update the config
            $newConfig = new stdClass();
            $newConfig->auto_bounce_next = $time + (int)$this->config->get('auto_bounce_frequency', 0);
            $newConfig->auto_bounce_last = $time;
            $this->config->save($newConfig);
            $bounceClass = acym_get('helper.bounce');
            $bounceClass->report = false;
            $bounceClass->stoptime = $queueHelper->stoptime;
            $newConfig = new stdClass();
            if ($bounceClass->init() && $bounceClass->connect()) {
                $nbMessages = $bounceClass->getNBMessages();
                $this->messages[] = acym_translation_sprintf('ACYM_NB_MAIL_MAILBOX', $nbMessages);
                $newConfig->auto_bounce_report = acym_translation_sprintf('ACYM_NB_MAIL_MAILBOX', $nbMessages);
                $this->detailMessages[] = acym_translation_sprintf('ACYM_NB_MAIL_MAILBOX', $nbMessages);
                if (!empty($nbMessages)) {
                    $bounceClass->handleMessages();
                    $bounceClass->close();
                    $this->processed = true;
                }
                $this->detailMessages = array_merge($this->detailMessages, $bounceClass->messages);
            } else {
                $bounceErrors = $bounceClass->getErrors();
                $newConfig->auto_bounce_report = implode('<br />', $bounceErrors);
                //We add "bounce handling" just before the error so the user knows where it comes from...
                if (!empty($bounceErrors[0])) {
                    $bounceErrors[0] = acym_translation('ACYM_BOUNCE_HANDLING').' : '.$bounceErrors[0];
                }
                $this->messages = array_merge($this->messages, $bounceErrors);
                $this->processed = true;
                $this->errorDetected = true;
            }
            $this->config->save($newConfig);

            //Check on the time limitation so we stop the process if we reached it
            if (!empty($queueHelper->stoptime) && time() > $queueHelper->stoptime) {
                return true;
            }
        }

        // Step 7: Automations
        if (!in_array('automation', $this->skip) && acym_level(2)) {
            $automationClass = acym_get('class.automation');
            $automationClass->trigger('classic');

            $userStatusCheckTriggers = [];
            acym_trigger('onAcymDefineUserStatusCheckTriggers', [&$userStatusCheckTriggers]);
            $automationClass->trigger($userStatusCheckTriggers);

            if (!empty($automationClass->report)) {
                if ($automationClass->didAnAction) $this->processed = true;
                $this->messages = array_merge($this->messages, $automationClass->report);
            }

            //Check on the time limitation so we stop the process if we reached it
            if (!empty($queueHelper->stoptime) && time() > $queueHelper->stoptime) {
                return true;
            }
        }

        // Step 8: Automatic campaign
        if (!in_array('campaign', $this->skip) && acym_level(2)) {
            $campaignClass = acym_get('class.campaign');
            $campaignClass->triggerAutoCampaign();
            if (!empty($campaignClass->messages)) {
                $this->messages = array_merge($this->messages, $campaignClass->messages);
                $this->processed = true;
            }
        }

        return true;
    }

    public function report()
    {
        //Send the report
        $sendreport = $this->config->get('cron_sendreport');
        $mailer = acym_get('helper.mailer');

        if (($sendreport == 2 && $this->processed) || $sendreport == 1 || ($sendreport == 3 && $this->errorDetected)) {
            $mailer->report = false;
            $mailer->autoAddUser = true;
            $mailer->checkConfirmField = false;
            $mailer->addParam('report', implode('<br />', $this->messages));
            $mailer->addParam('mainreport', $this->mainmessage);
            $mailer->addParam('detailreport', implode('<br />', $this->detailMessages));

            $receiverString = $this->config->get('cron_sendto');
            $receivers = [];
            if (substr_count($receiverString, '@') > 1) {
                $receivers = explode(' ', trim(preg_replace('# +#', ' ', str_replace([';', ','], ' ', $receiverString))));
            } else {
                $receivers[] = trim($receiverString);
            }

            if (!empty($receivers)) {
                foreach ($receivers as $oneReceiver) {
                    $mailer->sendOne('acy_report', $oneReceiver);
                }
            }
        }

        if (!$this->executed) {
            return;
        }

        if ($this->processed) {
            $this->saveReport();
        }

        $newConfig = new stdClass();
        $newConfig->cron_report = implode("\n", $this->messages);
        if (strlen($newConfig->cron_report) > 800) {
            $newConfig->cron_report = substr($newConfig->cron_report, 0, 795).'...';
        }
        $this->config->save($newConfig);
    }

    public function saveReport()
    {
        $saveReport = $this->config->get('cron_savereport');
        if (empty($saveReport)) {
            return;
        }

        $reportPath = $this->config->get('cron_savepath');
        if (empty($reportPath)) {
            return;
        }

        // We replace the year and the month in the file
        $reportPath = str_replace(['{year}', '{month}'], [date('Y'), date('m')], $reportPath);

        $reportPath = acym_cleanPath(ACYM_ROOT.trim(html_entity_decode($reportPath)));

        // We create the folder and an htaccess on it if it's not already there
        acym_createDir(dirname($reportPath), true, true);

        //We only leave the first one so that we can see warnings...
        ob_start();
        file_put_contents($reportPath, "\r\n"."\r\n".str_repeat('*', 20).str_repeat(' ', 5).acym_getDate(time()).str_repeat(' ', 5).str_repeat('*', 20)."\r\n".implode("\r\n", $this->messages), FILE_APPEND);
        if ($saveReport == 2 && !empty($this->detailMessages)) {
            @file_put_contents($reportPath, "\r\n"."---- Details ----"."\r\n".implode("\r\n", $this->detailMessages), FILE_APPEND);
        }
        $potentialWarnings = ob_get_clean();

        if (!empty($potentialWarnings)) {
            $this->messages[] = $potentialWarnings;
        }
    }
}
