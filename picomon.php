<?php
# ----------------------------------------------------------------------------
# PicoMon Main Script picomon.php
# Rev A 08/13/16
# (c) W.J. Riley Hamilton Technical Services All Rights Reserved
# ----------------------------------------------------------------------------
# These scripts are loosely based on the webform example
# in the PHPlot referfence manual.
# ----------------------------------------------------------------------------
# You must enter the database logon credentials into this script.
# ----------------------------------------------------------------------------
# Hint: Set display_errors=on in php.ini for development, off for deployment.
# ----------------------------------------------------------------------------
# Programming note: These functions mainly use global variables rather than
# long parameter lists or a parameter array.
# ----------------------------------------------------------------------------
# Debugging note: Values can be put into the plot title as a way to show them.
# ----------------------------------------------------------------------------
# Functions:
#    build_url()
#    begin_page()
#    end_page()
#    show_descriptive_text()
#    show_user_prompt()
#    fill_list()
#    show_form()
#    show_graph()
#    connect_to_db()
#    get_current_mjd()
#    get_begin_mjd()
#    get_tau()
#    get_meas_info()
# ----------------------------------------------------------------------------

# This file is the main script picomon.php for the PicoMon web app
# which displays the phase data for a selected active PicoPak module.
# This script does not use PHPlot. When first accessed from a browser
# (with no parameters), it displays only the form and descriptive text.
# When a PicoPak module is selected, the same script runs again.
# That time the script receives form parameters, and
# displays a PHPlot plot of the phase data read from the PicoPak database.
# To display the plot, the script generates an image (img) tag
# which references the second picomon_img.php script
# that generates the plot image.

# The form parameters are shown in the $param array below:
# The PicoPak S/N and code, the PicoScan channel letter and #,
# and the beginning and end MJDs and tau for the measurement run.
# The end MJD is the current one, and the S/N is selected from
# a pulldown list of the currently active modules.
# That S/N is then used to read the corresponding phase data.
# Other parameters are the database logon credentials (host, db name,
# user name and password), and the width and height of the plot.
 	
# The script begins with the descriptive comments,
# and then defines the name of the other script,
# the image size, the parameter defaults
# and the database login credentials. 

# Name of php script which generates the actual plot:
define('GRAPH_SCRIPT', 'picomon_img.php');
# Plot size
define('GRAPH_WIDTH', 600);
define('GRAPH_HEIGHT', 400);

# Database login credentials (hard-coded here).
# Provisions could be added for the user to enter them
# or they could be read from a configuration file
# but it seems reasonable to require that they be edited
# here in the script, especially if the web server is
# on the same machine as the database server
# and is therefore localhost or 127.0.0.1.
# NOTE: The login credentials are not encrypted
# so they are visible in this file
# IMPORTANT: Edit PostgreSQL database login credentials here:
define('HOSTADDR', '192.168.2.40');
define('DBNAME', 'ppd');
define('USER', 'postgres');
define('PASSWORD', 'root');

# Display control macros for testing
# Set these TRUE or FALSE to enable or disable them
# Normally they are all set FALSE
define('SHOW_START_MJD', FALSE);
define('SHOW_NOW_MJD', FALSE);
define('SHOW_PICOPAK_LIST', FALSE);
define('SHOW_NUM_ACTIVE', FALSE);
define('SHOW_MEAS_TAU', FALSE);
define('SHOW_PARAMETERS', FALSE);
define('SHOW_MEAS_PARAMS', FALSE);
define('SHOW_MEAS_INFO', FALSE);
define('SHOW_URL1', FALSE);
define('SHOW_URL2', FALSE);

# Global variables
# Number of active measurements
$num_active = 0;
# PicoPak S/N
$sn = 0;
# PicoPak S/N code (negative for PicoScan channels)
$n = 0;
# PicoScan channel #
$c = 0;
# PicoScan channel letter
$ch = ' ';
# Array of measurement S/Ns, codes, channel # and channel letter
# e.g., 110A, -440, 0, A
# This becomes an array of four element arrays
# containing the $sn, $n, $c and $ch for each active measurement
# $meas[row][col] where row is measurement module and col is parameter
# Indices are zero-based
# Row index goes from 0 to $num_active-1
# Column index goes from 0 to 3 
$meas = array(array());
# PostgreSQL deatabase logon credentials
# IMPORTANT: These are hard-coded in macros above
# Edit them there as required - no user entry provided
$db_host = HOSTADDR;
$db_name = DBNAME;
$db_user = USER;
$db_password = PASSWORD;
# PostgreSQL connection handle 
$pg = 0;
# PostgreSQL query result pointer
$result = 0;
# Beginning MJD
$begin_mjd = 0;
# End (Current) MJD
$end_mjd = 0;
# Measurement tau
$tau = 0;
# Measurement span
$days = 0;
$hours = 0;
$mins = 0;
$sec = 0;
# We need to send info to picomon_img.php script
# It needs both the coded module S/N, n,
# and the displayed S/N, sn, channel letter, ch and number, c,
# and the start and end mjds, begin & end, and the tau.
# There are no useful default values for most of those
# but we put in some placeholder values.
# It also needs the database login credentials
# host, db, user and pw,
# and includes values for the plot type, width and height
# They are all put into an associative array as follows:
$param = array("n" => -440,
 "sn" => 110,
 "ch" => 'A',
 "c" => 0,
 "begin" => 57604.5,
 "end" => 57605.5,
 "tau" => 5.0,
 "host" => '127.0.0.1',
 "db" => 'ppd',
 "user" => 'postgres',
 "pw" => 'root',
 "type" => 'freq',
 "w" => 1024,
 "h" => 768);
# We define the associative array $measinfo
# to hold several items of information about the current measurement
$measinfo = array("desc" => ' ',
 "signame" => ' ',
 "refname" => ' ');

# Module # from list
$module = 0;

# Function build_url() is used to generate a URL with parameters
# for the picomon_img.php script. The parameters are in an array.
# The return value is a relative or complete URL.
# It is called by show_graph()
#
# Build a URL with escaped parameters:
#   $url - The part of the URL up through the script name
#   $param - Associative array of parameter names and values
# Returns a URL with parameters. Note this must be HTML-escaped if it is
# used as an href value. The & between parameters is not pre-escaped.
#
# Function to build a URL:
function build_url($url, $param)
{
    $sep = '?';  // Separator between URL script name and first parameter
    foreach ($param as $name => $value) {
        $url .= $sep . urlencode($name) . '=' . urlencode($value);
        $sep = '&';   // Separator between subsequent parameters
    }
    
    # For testing - show URL
    if(SHOW_URL1)
    {
        echo "url=$url   ";
    }

    return $url;
}

# The function begin_page() creates the HTML at the top of the page.
#
# Function to output the start of the HTML page:
function begin_page($title)
{
echo <<<END
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
                      "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
<title>$title</title>
</head>
<body>
<h1>$title</h1>

END;
}

# The function end_page() creates the HTML at the end of the page.
#
# Function to output the bottom of the HTML page:
function end_page()
{
echo <<<END
</body>
</html>

END;
}


# The function show_descriptive_text() produces HTML text which
# describes the form. This goes above the form on the web page.
#
# Function to output text which describes the form:
function show_descriptive_text()
{
echo <<<END
<p>
This page displays a plot of the phase data for an active PicoPak clock measurement module.
</p>

END;
}

# The function show_user_prompt() produces HTML text which
# prompts the user to select a PicoPak module and plot its data.
#
# Function to output text which gives user prompt:
function show_user_prompt()
{
echo <<<END
<p>
Select the desired PicoPak S/N from the list and press Plot.
</p>

END;
}

# The function show_form() outputs the HTML form.
# This includes a list box for the module and a submit button.
# The form action URL is this script itself, so we use the SCRIPT_NAME
# value to self-reference the script.
#
# Our PicoPak Web Monitor web page has a list control
# for the user to select the desired active module by its S/N.
# The S/N is shown as a number like 110 for a PicoPak
# and a number and letter like 110A fror a PicoScan channel.
# An HTML select list control is created with <SELECT> and </SELECT> tags.
# One starts with a form and adds the select list to it.
# We include all active PicoPak modules
# and all active PicoScan channels
# The active=true item in the measurement_modules table
# flags the active modules (+sn) and channels (-sn) with:
# SELECT sn FROM measurement_modules WHERE active=TRUE
# Then we put those S/Ns into the select list,
# converting negative sn codes like -439 to PicoScan S/Ns like 110B.
#
# Function to fill a list of active PicoPaks and PicoScan channels:
function fill_list()
{
    # INPUTS
    # Use the global connection handle in this function
    GLOBAL $pg;
    # Use the global parameter array
    # It has default parameter values before show_graph() call
    GLOBAL $param;

    # OUTPUTS
    # Output the global # active measurements from this function
    GLOBAL $num_active;
    # Output the global measurement array from this function
    GLOBAL $meas;

    # LOCALS
    # # PicoScan channels
    $num_chs = 0;
    # Index
    $i = 0;

    # START OF CODE
    # Compose query
    $query = "SELECT sn FROM measurement_modules WHERE active=TRUE";

     # Perform query
    $result = pg_query($pg, $query);

    # Loop through the results
    # Save the results in the $meas array of arrays
    while($row = pg_fetch_array($result, NULL, PGSQL_ASSOC))
    {
        # Set indisplay_meas(dex to zero-based row number
	$i = $num_active;

        # Increment # active measurements
        $num_active++;

        # Set global database code
        $n = (int)$row['sn'];

	# Set the global S/N
	$sn = $n;

        # If the result of the query, n, is positive, it is the actual PicoPak S/N
        if(((int)$row['sn']) > 0)
        {
            $c  = ' ';
            $ch = ' ';
        }

        # If the result is negative, it correcponds to a PicoScan channel
        # denoted as (-4*S)+C where S=S/N and C=Chan # = 0-3 = A-D

        # Find display_meas(actual S/N and channel if this is PicoScan data
        # S = (ceil) (-$n/4), e.g., if n=-439, S=110
        # C = 4*S + n, e.g., if  n=-439, S=110, C=1= Chan B    
        if(((int)$row['sn']) < 0)
        {
            # Increment PicoScan channel count
            $num_chs++;

            # Find actual S/N     
            $sn = ceil(-$row['sn']/4);
    
            # Find channel #
            $c = ((int)$sn*4) + (int)$row['sn'];
        
            # Set channel letter
    	    if($c==0) $ch='A';
	    if($c==1) $ch='B';
	    if($c==2) $ch='C';
            if($c==3) $ch='D';
         }

        # Save the measurement parameters
        # Each array row is an array of S/N, code, ch # & ch letter
        $meas[$i][0] = $sn;
        $meas[$i][1] = $n;
        $meas[$i][2] = $c;
        $meas[$i][3] = $ch;

        if(SHOW_PICOPAK_LIST)
        {
            # Show S/N
            if($ch==' ')
            {
                echo("The S/N of active PicoPak #$num_active = $sn.<br>");
            }
            # Show S/N and channel
            else 
            {
                echo("The S/N of active PicoPak #$num_active = $sn$ch (PicoScan $n).<br>");
            }
        }
    }

    if(SHOW_PICOPAK_LIST)
    {
        echo("<br>");
    }

    if(SHOW_NUM_ACTIVE)
    {
        # Show # active modules (rows)
        $num = pg_num_rows($result);
        echo("There are $num active PicoPak measurements ($num_chs PicoScan channels)<bk>");
    }
    
   if(SHOW_PARAMETERS)
    {
        # Show parameters
        echo("<br />");        
        echo("In fill_list(): Parameters: ");
        echo("S/N Code = ".$param['n'].", ");
        echo("S/N Display = ".$param['sn'].", ");
        echo("Channel = ".$param['ch'].", ");
        echo("Begin = ".$param['begin'].", ");
        echo("End = ".$param['end']);
        echo("PG = ".$param['pg']);
        echo("Width = ".$param['w'].", ");
        echo("Height = ".$param['h']);
        echo("<br />");
    }

    if(SHOW_MEAS_PARAMS)
    {
        echo("<br />");
        echo("In fill_list(): meas[] array: <br />");

        for($i=0; $i<$num_active; $i++)
        {
            echo("Module".$i.": ");
            echo("S/N"." = ".$meas[$i][0]);
            echo(", Code"." = ".$meas[$i][1]);
            echo(", Ch #"." =".$meas[$i][2]);
            echo(" ,Ch Letter"." = ".$meas[$i][3]);
            echo("<br />");
        }
    }
}

# Output the web form.
# The web form resubmits to this same script for processing.
# The $param array contains default values for the form.
#
# Function to output the web form:
function show_form($param)
{
    # INPUTS
    # Use the global # active measurements in this function
    GLOBAL $num_active;
    # Use the global measurement array in this function
    GLOBAL $meas;

    # OUTPUTS
    # Output the global selected S/N, code, channel #
    # and channel letter from this function
    GLOBAL $sn;
    GLOBAL $n;
    GLOBAL $c;
    GLOBAL $ch;
    # Output the global module # selected from list
    GLOBAL $module;

    # When the browser is opened, the 1st module is selected.
    # The session variable then saves the selected module
    # while the browser is open
    # The same module plot can be refreshed simply by pressing Plot
    # after it has to be selected twice.

    # START OF CODE
    $action = htmlspecialchars($_SERVER['SCRIPT_NAME']);

    # Check that we have a saved module #
    # We won't have one if the web browser has just been opened
    if(empty($_SESSION))
    {
        # Set it to 1st module in list
        $_SESSION["module"] = 0;
    }

    # For testing
    // echo(" Saved Module # = " . $_SESSION["module"] . "<br /br>");

echo <<<END

<form name="f1" id="f1" method="post" action="$action">
  <table cellpadding="0" summary="Entry form">
    <tr
      <td align="left">
        <select name="module">

END;

    # Put the module S/Ns and channels into the select list
    # Loop through the measurements
    for($i=0; $i<$num_active; $i++)
    {
        # Put the PicoPak S/Ns and channel letters into the select list
        # The value is an index into the $meas table
        # We select the module that was previously selected
        if($i == $_SESSION["module"]) // Is this the previously selected module?
        {
            echo "<option selected value=\"$i\">" . $meas[$i][0] . $meas[$i][3];
        }
        else // Not selected
        {
            echo "<option value=\"$i\">" . $meas[$i][0] . $meas[$i][3];
        }
    }

echo <<<END

        </select> <br> <br>
      </td>
      <td align="left"><input type="submit" value="Plot">
      </td>
    </tr>

  </table>
</form>

END;

    # Check that we have a PicoPak module selected
    if(empty($_POST))
    {
        # If not, set it to the saved module #
        $_POST["module"] = $_SESSION["module"];
    }

    # Get selected module
    $module = $_POST["module"];

    // For testing
    // echo("<br /br>" . "Selected Module # = " . $module. "<br /br>");

    # Save selected module
    $_SESSION["module"] = $module;

    # Assign parameters for selected module
    $sn = $meas[$module][0];
    $n = $meas[$module][1];
    $c = $meas[$module][2];
    $ch = $meas[$module][3];

    // For testing
    // echo(", S/N = " . $sn . ", Code = " . $n . " ,Chan # = " . $c . " ,Chan Letter = " . $ch);
}

# The function show_graph() produces the HTML which will invoke
# the second script to produce the graph.
# This is an image (img) tag which references the second script,
# including the parameters the script needs to generate the plot.
# The HTML also specifies the width and height of the plot image.
# This is not necessary, however it helps the browser lay out the page
# without waiting for the image script to complete.
#
# Display a graph.
# This function creates the portion of the page that contains the
# graph, but the actual graph is generated by the $GRAPH_SCRIPT script.
#
# Function to show a plot of phase data:
function show_graph()
{
    # INPUTS
    GLOBAL $n;
    GLOBAL $sn;
    GLOBAL $c;
    GLOBAL $ch;
    GLOBAL $begin_mjd;
    GLOBAL $end_mjd;
    GLOBAL $tau;
    GLOBAL $db_host;
    GLOBAL $db_name;
    GLOBAL $db_user;
    GLOBAL $db_password;
    GLOBAL $days;
    GLOBAL $hours;
    GLOBAL $mins;
    GLOBAL $sec;
    GLOBAL $measinfo;

    # OUTPUTS
    GLOBAL $param;

    # START OF CODE
    # Estimate # data points
    # This is easier than doing a query and is close enough
    $points = (int)(($end_mjd - $begin_mjd) * 86400.0 / $tau); 
    
    # Insert URL parameters
    $param['n'] = $n;
    $param['sn'] = $sn;
    $param['c'] = $c;
    $param['ch'] = $ch;
    $param['begin'] = $begin_mjd;
    $param['end'] = $end_mjd;
    $param['tau'] = $tau;
    $param['host'] = $db_host;
    $param['db'] = $db_name;
    $param['user'] = $db_user;
    $param['pw'] = $db_password;
    # Include the width and height as parameters:
    $param['w'] = GRAPH_WIDTH;
    $param['h'] = GRAPH_HEIGHT;

    # URL to the graphing script, with parameters, escaped for HTML:
    $img_url = htmlspecialchars(build_url(GRAPH_SCRIPT, $param));

    # For testing
    if(SHOW_URL2)
    {
        echo "  img_url=$img_url";
    }

    echo <<<END
<hr>
<p>

Plot of $points points of phase data from the $measinfo[0] run for $measinfo[1] vs $measinfo[2] with a $tau second tau for PicoPak S/N $sn$ch from MJD $begin_mjd to $end_mjd, a span of $days days, $hours hours, $mins minutes and $sec seconds:

<p><img src="$img_url" width="{$param['w']}" height="{$param['h']}"
    alt="Phase data plot.">

END;
}

# Connect to PicoPak database
# or display error message if can't connect
#
# Function to connect to PicoPak database:
function connect_to_db()
{
    # INPUTS
    # Use the logon credentials in this function
    GLOBAL $db_host;
    GLOBAL $db_name;
    GLOBAL $db_user;
    GLOBAL $db_password;

    # OUTPUTS
    # Output the global connection handle from this function
    GLOBAL $pg;

    # START OF CODE
    $pg = pg_connect("hostaddr=$db_host dbname=$db_name user=$db_user password=$db_password")
    or die("Can't connect to PicoPak database");
}

# Function to get current MJD, which is also the end_mjd for an active run.
# We get the current time from the local operating system
# which is usually the same as that of the database server
# But it should also be OK even on another computer
# as long as both clocks are synchronized via NTP
# The time does not have to be very precise: +/- 5 seconds is OK
# We use the PHP time() function which returns the UNIX time,
# the # of seconds since UTC 00:00:00 1 January 1970 = MJD 40587
#
# Function to get the current MJD:
function get_current_mjd()
{
    # NO INPUTS

    # OUTPUTS
    # Output the global end_mjd from this function
    GLOBAL $end_mjd;

    # START OF CODE
    $end_mjd = 40587.0 + time() / 86400.0;

    if(SHOW_NOW_MJD)
    {
        $format = "The current MJD is %f.";
        printf($format, $end_mjd);
    }
}

# Function to get the begin_mjd for the selected run.
# We get this from the measurements_list table
# as the largest begin_mjd value for the selected sn
# using the query:
# SELECT begin_mjd FROM measurement_list WHERE sn=$sn ORDER BY begin_mjd DESC LIMIT 1 
#
# Function to get begin_mjd of the measurement:
function get_begin_mjd()
{
    # INPUTS
    # Use the global connection
    GLOBAL $pg;
    # Use the global code in this function
    GLOBAL $n;

    # OUTPUTS
    # Output the global begin_mjd from this function
    GLOBAL $begin_mjd;

    # Note: get_begin_mjd() must be called after fill_list so that $n is set
 
    # START OF CODE
    # Compose query
    $query = "SELECT begin_mjd FROM measurement_list WHERE sn=$n
    ORDER BY begin_mjd DESC LIMIT 1";

    # Perform query
    $result = pg_query($pg, $query);

     # Get result (the begin_mjd of the selected PicoPak)
    list($begin_mjd) = pg_fetch_row($result);

    if(SHOW_START_MJD)
    {
        echo("<br>The beginning MJD for the run is $begin_mjd.");
    }
}

# The measurement span is simply the difference between the end and begin MJDs
# expressed in days, hours, minutes and seconds.
#
# Function to calculate the measurement span:
function calc_span()
{
    # INPUTS
    # Use the global begin_mjd in this function
    GLOBAL $begin_mjd;
    # Use the global end_mjd in this function
    GLOBAL $end_mjd;

    # OUTPUTS
    # Output the global span in days, hours, minutes and seconds
    GLOBAL $days;
    GLOBAL $hours;
    GLOBAL $mins;
    GLOBAL $sec;

    # START OF CODE
    # Calculate the measurement span
    $span = $end_mjd - $begin_mjd;
    $days = (int)($end_mjd - $begin_mjd);
    $hours = (int)(($span - $days)*24);
    $mins = (int)(((($span - $days)*24) - $hours)*60);
    $sec = (int)(((($span - $days)*24 - $hours)*60 - $mins)*60);

    # For testing
    // echo("Span = $span, ");
    // echo("Days = $days, ");
    // echo("Hours = $hours, ");
    // echo("Mins = $mins, ");
    // echo("Sec = $sec");
}

# We get the measurement tau from the measurement_list table
# corresponding to the largest begin_mjd for the selected S/N.
#
# Function to get the measurement tau:
function get_tau()
{
    # INPUTS
    # Use the global connection
    GLOBAL $pg;
    # Use the global S/N code in this function
    GLOBAL $n;

    # OUTPUTS
    # Use the global tau in this function
    GLOBAL $tau;

    # START OF CODE
    # Note: get_tau() must be called after fill_list so that $n is set
 
    # Compose query
    $query = "SELECT tau FROM measurement_list WHERE sn=$n ORDER BY begin_mjd DESC LIMIT 1";

    # Perform query
    $result = pg_query($pg, $query);

    # Get result (the tau of the selected PicoPak)
    list($tau) = pg_fetch_row($result);

    If(SHOW_MEAS_TAU)
    {
        # Display measurement tau    
        echo("The measurement tau is $tau seconds for S/N code $n.");
    }
}

# Function to get information about the PicoPak measurement.
# We get the names of signal and reference clocks
# and measurement description for the current measurement.
#
# Function to get measurement information: 
function get_meas_info()
{
    # INPUTS
    # Use the global connection
    GLOBAL $pg;
    # Use the global S/N code in this function
    GLOBAL $n;

    # OUTPUTS
    # Output info into the global measinfo array from this function
    GLOBAL $measinfo;

    # START OF CODE
    # Get last sig_id, ref_id and description entries from
    # the measurements_list table where sn = $n
    # into the variables $sigid and $refid
    # and the $measinfo array item desc
    # Compose query
    $query = "SELECT sig_id FROM measurement_list WHERE sn=$n ORDER BY begin_mjd DESC LIMIT 1";

    # Perform query
    $result = pg_query($pg, $query);

    # Get result (the sig_id of the selected measurement)
    list($sigid) = pg_fetch_row($result);

    # Compose query
    $query = "SELECT ref_id FROM measurement_list WHERE sn=$n ORDER BY begin_mjd DESC LIMIT 1";

    # Perform query
    $result = pg_query($pg, $query);

    # Get result (the ref_id of the selected measurement)
    list($refid) = pg_fetch_row($result);

    # Compose query
    $query = "SELECT description FROM measurement_list WHERE sn=$n ORDER BY begin_mjd DESC LIMIT 1";

    # Perform query
    $result = pg_query($pg, $query);

    # Get result (the description of the selected measurement)
    list($measinfo[0]) = pg_fetch_row($result);

    # Then get the clock_name from clock_name table where
    # clock_id = sig_id and clock_id = ref_id
    # Compose query
    $query = "SELECT clock_name FROM clock_names WHERE clock_id=$sigid";

    # Perform query
    $result = pg_query($pg, $query);

    # Get result (the signal clock name of the selected measurement)
    list($measinfo[1]) = pg_fetch_row($result);

    # Compose query
    $query = "SELECT clock_name FROM clock_names WHERE clock_id=$refid";

    # Perform query
    $result = pg_query($pg, $query);

    # Get result (the reference clock name of the selected measurement)
    list($measinfo[2]) = pg_fetch_row($result);
    
    # Show results
    If(SHOW_MEAS_INFO)
    {
        # Display measurement description    
        echo("The measurement description is \"$measinfo[0]\" for S/N code $n.<br />");
        
        # Display measurement description    
        echo("The signal clock name is $measinfo[1] for S/N code $n.<br />");

        # Display measurement description    
        echo("The reference clock name is $measinfo[2] for S/N code $n.");
    }
}

# Finally, with all the functions defined, the main code is just a few lines.

# This is the main processing code.
# Note that we begin a $_SESSION to save the selected PicoPak module #
# between display of our web pages
# This value persists until the user closes his/her browser
# and is used to retain the module selection so the plot can be refreshed
# without reselecting it
session_start();
begin_page("PicoPak Web Monitor");
show_descriptive_text();
show_user_prompt();
connect_to_db();
fill_list();
show_form($param);
get_begin_mjd();
get_current_mjd();
get_tau();
calc_span();
get_meas_info();
show_graph();
end_page();
?>
