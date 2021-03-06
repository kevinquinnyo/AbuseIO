<?php
include('../../lib/core/loader.php');

if (empty($_GET['id']) || empty($_GET['token']) || !is_numeric($_GET['id'])) {
    die('<h2>401 - Unauthorized</h2>');
}

if (isset($_GET['lang']) && strlen($_GET['lang']) == 2){
    $infolang = $_GET['lang'];
} else {
    $infolang = 'en';
}

$report = reportGet($_GET['id']);
$token  = md5("${report['ID']}${report['IP']}${report['Class']}");

if ($_GET['token'] != $token) {
    die('<h2>401 - Unauthorized</h2>');
}

$title = "Abuse Self Help - Ticket {$report['ID']}";

$labelClass = array(
    'ABUSE'     => 'warning',
    'INFO'      => 'info',
    'ALERT'     => 'danger',
    'OPEN'      => 'warning',
    'CLOSED'    => 'info',
    'ESCALATED' => 'danger',
    'NO'        => 'warning',
    'YES'       => 'info',
    '0'         => 'warning',
    '1'         => 'info',
); 

if (!empty($_GET['action'])) {
    if ($_GET['action'] == 'addNote') {
        if (strlen($_GET['noteMessage']) < 10) {
            $changeMessage = "<span class='alert alert-danger'>Your reply was <b>not submitted</b>, because of insufficient information in the note.</span>";

        } elseif ($_GET['noteType'] == 'message') {
            reportNoteAdd('Customer', $_GET['id'], htmlentities(strip_tags($_GET['noteMessage'])));
            $changeMessage = "<span class='alert alert-success'>Your reply has been registered <b>successfully</b>. Thank you for your reply!</span>";

        } elseif ($_GET['noteType'] == 'ignore') {
            reportNoteAdd('Customer', $_GET['id'], htmlentities(strip_tags($_GET['noteMessage'])));
            reportIgnored($_GET['id']);
            $report['CustomerIgnored'] = 1;
            $report['CustomerResolved'] = 0;
            $changeMessage = "<span class='alert alert-info'>Your reply has been registered <b>successfully</b>. You will no longer receive new notifications about this event.</span>";

        } elseif ($_GET['noteType'] == 'resolve') {
            reportNoteAdd('Customer', $_GET['id'], htmlentities(strip_tags($_GET['noteMessage'])));
            reportResolved($_GET['id']);
            $report['CustomerIgnored'] = 0;
            $report['CustomerResolved'] = 1;
            $changeMessage = "<span class='alert alert-success'>Your reply has been registered <b>successfully</b>. This event has been marked as <b>resolved</b>.</span>";

        } else {
            $changeMessage = "<span class='alert alert-danger'>Your reply was <b>not submitted</b>, because the reply action is unknown.</span>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $title;?></title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-theme.min.css" rel="stylesheet">
    <!-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries -->
    <!--[if lt IE 9]>
      <script src="//oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
      <script src="//oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->
  </head>
  <body>
    <div class="container" style="padding: 0.5em 0.5em 4em;">

            <h1><?php echo $title; ?></h1>
            <hr>

            <?php if (!empty($changeMessage)) echo '<div style="margin: 2em 0;">'.$changeMessage.'</div>'; ?>

            <?php
                $statictext = APP . "/etc/ash.template";
                if (file_exists($statictext)) include($statictext);
            ?>

            <h2>Report information</h2>

            <dl class="dl-horizontal">
                <dt>IP address</dt>
                <dd><?php echo $report['IP']; ?></dd>

                <?php
                    $reverse = gethostbyaddr($report['IP']);
                    if ($reverse != $report['IP'] && $reverse !== false) {
                ?>
                <dt>Reverse DNS</dt>
                <dd><?php echo gethostbyaddr($report['IP']); ?></dd>
                <?php } ?>

                <?php if (!empty($report['Domain'])) { ?>
                <dt>Domain</dt>
                <dd><?php echo $report['Domain']; ?></dd>
                <?php } ?>

                <?php if (!empty($report['URI'])) { ?>
                <dt>URI</dt>
                <dd><?php echo $report['URI']; ?></dd>
                <?php } ?>

                <dt>Classification</dt>
                <dd><?php echo $report['Class']; ?></dd>

                <dt>Source</dt>
                <dd><?php echo $report['Source']; ?></dd>

                <dt>Type</dt>
                <dd><?php echo "<span class='label label-${labelClass[$report['Type']]}'>${report['Type']}</span>"; ?></dd>

                <dt>First Seen</dt>
                <dd><?php echo date("d-m-Y H:i", $report['FirstSeen']); ?></dd>

                <dt>Last Seen</dt>
                <dd><?php echo date("d-m-Y H:i", $report['LastSeen']); ?></dd>

                <dt>Report count</dt>
                <dd><?php echo $report['ReportCount']; ?></dd>

                <dt>Ticket status</dt>
                <dd><?php echo "<span class='label label-${labelClass[$report['Status']]}'>${report['Status']}</span>"; ?></dd>

                <dt>Reply status</dt>
                <dd><?php
                    if($report['CustomerIgnored'] == 1) {
                        echo "<span class='label label-warning'>CUSTOMER IGNORED</span>";
                    } elseif($report['CustomerResolved'] == 1) {
                        echo "<span class='label label-info'>CUSTOMER RESOLVED</span>";
                    } else {
                        echo "<span class='label label-warning'>AWAITING REPLY</span>";
                    }
                ?></dd>

            </dl>

            <?php
                $infotext = infotextGet($infolang, $report['Class']);
                if ($infotext) echo $infotext;
            ?>

            <h2>Additional information</h2>

        <?php

            $info_array = json_decode($report['Information'], true);
            if (empty($info_array)) {
                echo '<p>No information found</p>';
            } else {
                echo '<dl class="dl-horizontal">';
                foreach($info_array as $field => $value) {
                    echo "<dt>${field}</dt>";
                    echo "<dd>".htmlentities($value)."</dd>";
                }
                echo '</dl>';
            }
        ?>

        <h2>Feedback form</h2>
        <form method='GET'>
        <input type='hidden' name='action' value='addNote'>
        <input type='hidden' name='id'     value='<?php echo $_GET['id']; ?>'>
        <input type='hidden' name='token'  value='<?php echo $_GET['token']; ?>'>
            <div style="margin: 1em 0 1em;">
                <div><label for='noteMessage'>Your reply</label></div>
                <div><textarea rows="5" cols="70" name='noteMessage' style="width: 30em; height: 10em;"></textarea></div>
            </div>
            <div style="margin: 0.5em 0 0;"><input type="radio" name="noteType" value="message" checked> Reply</div>
<?php if($report['Type'] == "INFO") { ?>
            <div style="margin: 0.5em 0 0;"><input type="radio" name="noteType" value="ignore"> Reply and mark as ignored</div>
<?php } ?>
            <div style="margin: 0.5em 0 0;"><input type="radio" name="noteType" value="resolve"> Reply and mark as resolved</div>
            <div style="margin: 1.5em 0 0;"><input type='submit' class='btn btn-primary btn-sm' name='' value='Submit'></div>
        </form>
    </div>
  </body>
</html>
