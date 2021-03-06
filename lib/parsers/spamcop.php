<?php
/******************************************************************************
* AbuseIO 3.0
* Copyright (C) 2015 AbuseIO Development Team (http://abuse.io)
*
* This program is free software; you can redistribute it and/or
* modify it under the terms of the GNU General Public License
* as published by the Free Software Foundation; either version 2
* of the License, or (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program; if not, write to the Free Software Foundation
* Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301, USA.
******************************************************************************/


/*
** Function: parse_spamcop
** Parameters: 
**  message(array): The message array returned from the receive_mail function
** Returns: True on parsing success (or false when it failed)
*/
function parse_spamcop($message) {
    $outReport                  = array('source' => 'Spamcop');
    $outReport['information']   = array();
    $outReport['type']          = 'ABUSE';

    if (
        strpos($message['from'], "@reports.spamcop.net") !== FALSE && 
        isset($message['arf']) && 
        is_array($message['arf']) && isset($message['arf']['report'])
    ) {
        // We've got a ARF formatted message
        // The e-mail parses should have noticed the multipart elements and created a 'arf' array

        foreach($message['arf']['headers'] as $key => $value) {
            if(is_array($value)) {
                foreach($value as $index => $subvalue) {
                    $outReport['information']["${key}${index}"] = $subvalue;
                }
            } else {
                $outReport['information'][$key] = $value;
            }
        }

        if(strpos($message['body'], 'Comments from recipient') !== false) {
            preg_match("/Comments from recipient.*\s]\n(.*)\n\n\nThis/s", str_replace(array("\r","> "), "", $message['body']), $match);
            $outReport['information']['recipient_comment'] = str_replace("\n", " ", $match[1]);
        }

        $message['arf']['report'] = str_replace("\r", "", $message['arf']['report']);
        preg_match_all('/([\w\-]+): (.*)[ ]*\r?\n/',$message['arf']['report'],$regs);
        $fields = array_combine($regs[1],$regs[2]);

        if(!empty($fields['Source-IP']) && !empty($fields['Reported-URI'])) {
            // This is a spamvertized report and we need to ignore the IP and use the domain
            $outReport['class']         = "Spamvertised web site";
            unset($fields['Source-IP']);

            // grab domain and path from reported uri
            $url_info = parse_url($fields['Reported-URI']);
            if(empty($url_info['host']) || empty($url_info['path'])) {
                logger(LOG_ERR, __FUNCTION__ . " ARF Report is missing essential url fields");
                return false;
            } else {
                $outReport['domain'] = $url_info['host'];
                $outReport['uri'] = $url_info['path'];
            }

            // grab domain from body

            $regex = str_replace("/", "\/", $outReport['domain']);
            preg_match("/.*${regex}.* is (.*);/s", $message['body'], $match);
            if(valid_ip($match[1])) {
                $fields['Source-IP'] = $match[1];
            } else {
            }
        } else {
            $outReport['class']         = "SPAM";
        }

        if(empty($fields['Source-IP'])) {
            // Sometimes Spamcop has a trouble adding the correct fields. The IP is pretty
            // normal to add. In a last attempt we will try to fetch the IP from the body

            preg_match("/Email from (?<ip>[a-f0-9:\.]+) \/ ${fields['Received-Date']}/s",$message['body'],$regs);
            if(valid_ip($regs['ip'])) {
                $fields['Source-IP'] = $regs['ip'];
            }
        }

        if(empty($fields['Source-IP']) || empty($fields['Received-Date'])) {
            logger(LOG_ERR, __FUNCTION__ . " ARF Report is missing essential report fields");
            return false;
        }

        $outReport['ip']            = $fields['Source-IP'];
        $outReport['timestamp']     = strtotime($fields['Received-Date']);

        $reportID = reportAdd($outReport);
        if (!$reportID) return false;
        if(KEEP_EVIDENCE == true && $reportID !== true) { evidenceLink($message['evidenceid'], $reportID); }

    } elseif (strpos($message['from'], "@reports.spamcop.net") !== FALSE) {
        // We've got a SPAMCOP formatted message
        // most inline SPAM messages contain the original SPAM message
        // Lets attempt to grab that part of the message and collect the interesting bits
        $body            = explode("[ Offending message ]", $message['body']);
        if (isset($body[1])) {
            $spam_message   = receive_mail(array('type' => 'INTERNAL', 'message' => $body[1]));

            if (isset($spam_message['headers']['from'])) {
                $outReport['information']['from'] = $spam_message['headers']['from'];
            }
            if (isset($spam_message['headers']['return-path'])) {
                $outReport['information']['return-path'] = $spam_message['headers']['return-path'];
            }
            if (isset($spam_message['headers']['subject'])) {
                $outReport['information']['subject'] = $spam_message['headers']['subject'];
            }
            if (isset($spam_message['headers']['x-mailer'])) {
                $outReport['information']['x-mailer'] = $spam_message['headers']['x-mailer'];
            }
            if (is_array($spam_message['headers']['received'])) {
                foreach( $spam_message['headers']['received'] as $id => $received) {
                    $field = "header_line". ($id + 1);
                    $outReport['information'][$field] = "received " . $received;
                }
            }
        }

        // Split the main body text to be able to detect the message type and should end up with
        $tmp = preg_split("/(\r)?\n/", $body[0]);
        // element 1: 'spamcop vXXXX' or 'spamcop summary' types
        // element 2: empty line
        // element 3: domain or IP and the classification
        // element 4: the url to contact spamcop for this report
        // element 5: IP (again) and timestamp 

        if (strpos($tmp[3], "Spamvertised web site") !== false) {
             // This part handles 'Spamvertised web site' complaints
            $tmpvar = explode(": ", $tmp[3]);
            $outReport['class'] = $tmpvar[0];

            $url_info = parse_url($tmpvar[1]);

            if(valid_ip($url_info['host']) == true) {
                $url_info['host'] = "";
            }

            if (!isset($url_info['port']) && $url_info['scheme'] == "http") {
                $url_info['port'] = "80";
            } elseif (!isset($url_info['port']) && $url_info['scheme'] == "https") {
                $url_info['port'] = "443";
            } elseif(!isset($url_info['port'])) {
                $url_info['port'] = "";
            }

            if (!isset($url_info['path'])) {
                $url_info['path'] = "/";
            }

            $outReport['domain']        = $url_info['host'];
            $outReport['uri']           = $url_info['path'];
            $outReport['url']           = $tmpvar[1];

            $outReport['information']['scheme'] = $url_info['scheme'];
            $outReport['information']['port']   = $url_info['port'];

            $tmpvar = preg_split("/((; )|( is ))/i", $tmp[5]);
            $outReport['ip']            = $tmpvar[1]; 
            $outReport['timestamp']     = strtotime($tmpvar[2]);

        } elseif (strpos($tmp[3], "Email from") !== false) {
            // This part handles 'spam' complaints
            $outReport['class']         = "SPAM";

            $tmpvar = preg_split("/(( from )|( \/ ))/i", $tmp[3]);
            $outReport['ip']            = $tmpvar[1];
            $outReport['timestamp']     = strtotime($tmpvar[2]);
            $outReport['information']['reply_url'] = $tmp[4];

        } elseif (strpos($tmp[3], "Unsolicited bounce from") !== false) {
            // This part handles 'bounce' complaints
            // TODO - Waiting on sample
        } else {
            logger(LOG_ERR, __FUNCTION__ . " Unable to match the report, perhaps a new classification type?");
            logger(LOG_WARNING, __FUNCTION__ . " FAILED message from ${outReport['source']} subject ${message['subject']}");
            return false;
        }

        $reportID = reportAdd($outReport);
        if (!$reportID) return false;
        if(KEEP_EVIDENCE == true && $reportID !== true) { evidenceLink($message['evidenceid'], $reportID); }

    } elseif ($message['subject'] == "[SpamCop] summary report") {
        // Only trap, mole and simp are interesting. Ignore the user field
        // Only table the summary table from the body and turn it into a named array
        $start = strpos($message['body'], "     IP_Address Start/Length Trap User Mole Simp Comments\n                RDNS") + 80;
        $stop  = strpos($message['body'], "\n\n\n-- Key to Columns --");

        $summaries = substr($message['body'], $start, ($stop - $start));
        $match = "^\s*(?<ip>[a-f0-9:\.]+)\s+(?<date>\w+\s+\d+\s\d+)h\/(?<days>\d+)\s+(?<trap>\d+)\s+(?<user>\d+)\s+(?<mole>\d+)\s+(?<simp>\d+)(?:\s(?<comment>.*))\r?\n\s+(?<rdns>.*)";
        preg_match_all("/${match}/m", $summaries, $matches, PREG_SET_ORDER );

        foreach($matches as $id => $match) {
            if ($match['trap'] > 0 || $match['mole'] > 0 || $match['simp'] > 0) {

                $outReport['information']['trap']       = $match['trap'];
                $outReport['information']['mole']       = $match['mole'];
                $outReport['information']['simp']       = $match['simp'];
                $outReport['information']['comment']    = $match['comment'];

                $outReport['class']         = "SPAM Trap";
                $outReport['ip']            = $match['ip'];
                $outReport['timestamp']     = strtotime($match['date'] . ":00");

                $reportID = reportAdd($outReport);
                if (!$reportID) return false;
                if(KEEP_EVIDENCE == true && $reportID !== true) { evidenceLink($message['evidenceid'], $reportID); }

            } else { 
                /* Ignore user mails as we get a more details report from spamcop in a seperate mail */ 
                logger(LOG_DEBUG, __FUNCTION__ . " message item from ${outReport['source']} ignored because its a user message about ${match['ip']} which we already got");
            }
        }

    } elseif ($message['subject'] == "[SpamCop] Alert") {
        // If the receiver has enabled pager alerts when a spamtrap is hit we only receive an email with 
        // an IP address in the body, nothing more and nothing less. These alerts are pretty fast!

        $match = "^\s*(?<ip>[a-f0-9:\.]+)";
        preg_match("/${match}/", $message['body'], $match );

        $outReport['information']['Note'] = 'A spamtrap hit notification was received. These notifications do not provide any evidence.';
        $outReport['class']         = "SPAM Trap";
        $outReport['ip']            = $match['ip'];
        $outReport['timestamp']     = time();

        $reportID = reportAdd($outReport);
        if (!$reportID) return false;
        if(KEEP_EVIDENCE == true && $reportID !== true) { evidenceLink($message['evidenceid'], $reportID); }

    } else {
        logger(LOG_ERR, __FUNCTION__ . " The data from this e-mail was not in a known format");
        return false;
    }

    logger(LOG_INFO, __FUNCTION__ . " Completed message from ${outReport['source']} subject ${message['subject']}");
    return true;
}
?>
