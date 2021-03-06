<?php
# ----------------------------------------------------------------------------
# PicoPak Web Monitor Image Script picomon_img.php
# ----------------------------------------------------------------------------
# Rev 0 08/12/16 Everything basically working - uploaded to Github
# Rev A 08/13/16 Added frequency data plotting code
# Rev B 08/15/16 Added squared frequency plot if < 1000 points
#                Eliminated global variable use in several functions
# Rev C 08/16/16 Add writing of phase data file
# Rev D 08/18/16 Release 1.00
# Rev E 08/27/16 Add option to downsample phase data with AF
#       08/28/16 Minor editing and commenting
#       08/29/16 Add AF from URL parameter passed by main script
#                Release 1.10
# Rev F 08/31/16 Fix error in analysis tau
#                Release 1.20
# Rev G 09/04/16 Change determination of # points, end MJD and span.
#                Add display of current MJD in text above plot.
#                Release 1.30
# Rev H 09/05/16 Add optional freq avg and phase slope lines
#                Release 1.40
# Rev I 09/06/16 Fix error in phase slope line calc
#                Fix error in freq avg line calc
#                Add legend positioning
#                Release 1.50
#
# (c) W.J. Riley Hamilton Technical Services All Rights Reserved
# ----------------------------------------------------------------------------
# You must enter the data filename into this script.
# ----------------------------------------------------------------------------
# Functions:
#    connect_to_db()
#    read_data()
#    read_and_downsample_data
#    calc_freq_slope()
#    calc_freq_avg()
#    calc_sigma()
#    phase_to_freq()
#    scale_phase_data()
#    scale_freq_data()
#    write_data()
#    draw_graph()
# ----------------------------------------------------------------------------
# This second script generates the plot using PHPlot.
# The URL to this script, along with its parameters,
# is produced and sent by the main script.
# When the user's browser asks the web server for the image,
# this second script runs and generates the plot.

# Some comments re the # of data points and data array size:
# At some point (say 10k) the # of points in a plot reaches
# a value where more doesn't add insight, especially for a
# frequency plot where it often becomes just a solid band of noise.
# There are also issues of speed and resource limits.
# In fact, there appears to be some problem with PHP/PHPlot with
# data sets greater than around 100k points, a number easily exceeded
# by 1 second PicoPak data over longer than a day.
# So some sort of data averaging/decimation/downsampling seems needed.
# Downsampling is easy to do with phase data,
# and that is what is done here.  In fact, it's quite easy to do
# since the entire data set is extracted from the database
# and then put into a plot array of arrays for plotting.
# One can either bound the # of points with an averaging factor
# or set a "even" one.  The former seems appropriate for
# an automated process, while the later is best for manual analysis.
# Either is easily supported, and macros allow their choice here.
#
# The text before the plot does not change with the averaging factor
# but when AF>1, it is displayed as a legend.
#
# Other AF possibilities:
#   Manual entry via text, list or radio button controls
#   Force "even" AFs like multiples of 2, 5, 10
#   Let user enter AF on picomon.php command line
#   and pass it to this image script on its command line
#
# This last possibility seems best ans is implemented
# No AF or AF=0 entered by user => Use default MAXSIZE
# AF=1 => No averaging, use all data
# AF>1 => Do averaging by user-chosen factor
#
# Another note: When a PicoPak or PicoScan run ends w/o an end MJD
# being put into the database, the # of data points shown in the text
# may be much greater than their actual number.  Since that text
# is written before the actual # of points is known, it is not easy
# to adjust, and is left as s problem for the database to fix. 

require_once 'phplot.php';

# Global variables for this file
# The phase array is an array of arrays
# Each row has 3 items:
# An empty label string '', the x value (point #)
# and the phase value (which gets scaled)
$phase = array(array('', 0, 0));
# Also define a frequency data array of arrays
$freq = array(array('', 0, 0));
# 2nd database connection pointer
$pg2 = 0;
# PicoPak S/N code
$n = intval($_GET['n']);
# PicoPak S/N for display
$sn = intval($_GET['sn']);
# PicoScan channel
$ch = strval($_GET['ch']);
# Beginning data MJD
$begin = floatval($_GET['begin']);
# Ending data MJD (current MJD)
// $end = floatval($_GET['end']); // Not used
# Tau - This is the measurement tau
# The plotting and analysis tau is multiplied by AF
$tau = floatval($_GET['tau']);
# Averaging factor
$af = intval($_GET['af']);
# The phase data are downsampled by the averaging factor
# as it is put into the $phase[][] array, so all use of
# tau in this script is multiplied by $af except if
# the af in the URL is zero as a code for using MAXSIZE
if($af>0)
{
	$tau = $tau * $af;
}
# PostgreSQL logon credentials
$db_host = strval($_GET['host']);
$db_name = strval($_GET['db']);
$db_user = strval($_GET['user']);
$db_password = strval($_GET['pw']);
# Plot width in pixels
$w = intval($_GET['w']);
# Plot height in pixels
$h = intval($_GET['h']);
# Phase data units
$units = " ";
# Plot title
if($_GET['type'] == 'phase')
{
    $type = "phase";
    $title = "PicoPak Phase Data";
}
else
{
    $type = "freq";
    $title = "PicoPak Frequency Data";
}
# # phase data points, N
$numN = 0;
# # freq data points, M
$numM = 0 ;
# Phase slope
$slope = 0.0;
# Phase intercept
$intercept = 0.0;
# Average fractional frequency offset
$frequency = 0.0;
# Average frequency
$avg = 0.0;
# Raw sigma
$sigma = 0.0;
# Formatted ADEV
$adev = 0.0;
# Legends
$leg1 = '';
$leg2 = '';
$leg3 = '';
# Macros to control legends
define('SHOW_FREQ', TRUE);
define('SHOW_ADEV', TRUE);
# Macros to control plot lines
define('SHOW_AVG', TRUE);
define('SHOW_SLOPE', TRUE);

# Phase data folder
# Edit this name as desired
# You must have read/write permission in this folder
# A public folder is recommended where it can be accessed remotely
define('FOLDER', "/home/bill/Public/");
# Phase data filename
# A .dat (general data) or .phd (Stable32 phase data)
# extension is recommended
# Edit this name as desired
define('FILENAME', "picomon.dat");
#
# Macros to control downsampling
define('DOWNSAMPLE', TRUE);
define('MAXSIZE', 5000);
define('MANUAL_AF', FALSE);
define('AF', 1000); // Set value for manual AF

# No check for parameters supplied to this web page.
# Parameters S/B OK though the calling script
# Do not call this script directly with arbitrary parameters.

# ----------------------------------------------------------------------------
# Connect to PicoPak database
# or display error message if can't connect
# This 2nd connection is separate from that in 1st script
#
# Function to connect to PicoPak database
# Returns connection handle $pg2
function connect_to_db($db_host, $db_name, $db_user, $db_password)
{
    # INPUTS
    # Database logon credentials
    # $db_host;
    # $db_name;
    # $db_user;
    # $db_password;

    # OUTPUT
    # Database connection handle

    # START OF CODE
    $pg2 = pg_connect("hostaddr=$db_host dbname=$db_name
    user=$db_user password=$db_password")
    or die("Can't connect to PicoPak database");

    return $pg2;
}

# ----------------------------------------------------------------------------
# The following function reads the phase data for the selected
# PicoPak module from the PostgreSQL database measurerments table.
# We have the pg connection pointer for the database connection.
# The query is for all the meas values for the selected sn
# from the beginning MJD.
# The $phase array is an array of arrays
# each of which is a blank label string, an index, and a phase value.
#
# Function to read phase data from PostgreSQL database
# No return (void)
function read_data($pg2, $n, $begin)
{
    # INPUTS
    // GLOBAL $pg2;
    // GLOBAL $n;
    // GLOBAL $begin;

    # OUTPUTS
    GLOBAL $phase;
    GLOBAL $numN;
    GLOBAL $title; // For testing

    # Read phase data from database.
    # We read all of it in one query
    # asking for meas values from the measurements table
    # for selected S/N code starting at the beginning MJD.
    # We get the number of points from the row count.
    {
        # Compose query
        $query = "SELECT meas FROM measurements WHERE sn = $n
        AND mjd > $begin order by mjd";

        # Perform query
        $result = pg_query($pg2, $query);

        # Check result
        if(!$result)
        {
            # Query failed
            # Put message into the plot title to display it
            $title = "Query failed"; // For testing
        }   

        # Get # rows returned
        # Note: Can have OK result with no rows returned
	$numN = pg_num_rows($result);
        
        # For testing, put # points into the plot title to display it
        // $title = strval($numN);
        
        # Check that we got data
        if($numN==0)
        {
            # No Data
            # Put message into the plot title to display it
            $title = "No Data"; // For testing
        }

        # Initialize data point index
        $i = 0;

        # Fetch data and save it in $phase array
        # pg_fetch_data() fetches next row as an array of strings
        # (starting at 1st row) into array $row
        # (there is only 1 item per row, the meas value)
        # and returns zero when there are no more points
        while($row = pg_fetch_row($result))
        {
            # Save point in $phase array for data-data plot format
            # The 1st item in each $phase row is a blank label
            # The 2nd item in each $phase row is the data point #
            # It starts at 1 and goes to $numN
            # The 3rd item in each $phase row is the phase value
            $phase[$i][0] = '';
            $phase[$i][1] = $i+1;
            $phase[$i][2] = $row[0];
 
            # Increment index
            $i++;
        }
    }

    # For testing, examine the contents of the $phase array
    # The 1st index is the 2-dimensional array row
    # The 2nd index is the 2-dimensional array column
    // $title = strval($phase[0][2]);
}

# ----------------------------------------------------------------------------
# The following function reads the phase data for the selected
# PicoPak module from the PostgreSQL database measurerments table.
# with optional downsampling when it exceeds MAXSIZE
# We have the pg connection pointer for the database connection.
# The query is for all the meas values for the selected sn
# from the beginning MJD.
# The $phase array is an array of arrays
# each of which is a blank label string, an index, and a phase value.
#
# No AF or AF=0 entered by user => Use default MAXSIZE
# AF=1 => No averaging, use all data
# AF>1 => Do averaging by user-chosen factor
# Function to read phase data from PostgreSQL database
#
# No return (void)
function read_and_downsample_data($pg2, $n, $begin)
{
    # INPUTS
    // GLOBAL $pg2;
    // GLOBAL $n;
    // GLOBAL $begin;

    # OUTPUTS
    GLOBAL $phase;
    GLOBAL $numN;
    GLOBAL $af;
    GLOBAL $tau;
    GLOBAL $title; // For testing

    # Read phase data from database.
    # We read all of it in one query
    # asking for meas values from the measurements table
    # for selected S/N code starting at the beginning MJD.
    # We get the number of points from the row count.
    {
        # Compose query
        $query = "SELECT meas FROM measurements WHERE sn = $n
        AND mjd > $begin order by mjd";

        # Perform query
        # This extracts all the selected phase data from the database
        $result = pg_query($pg2, $query);

        # Check result
        if(!$result)
        {
            # Query failed
            # Put message into the plot title to display it
            $title = "Query failed"; // For testing
        }   

        # Get # rows returned = # phase data points
        # Note: Can have OK result with no rows returned
	$numN = pg_num_rows($result);
        
        # For testing, put # points into the plot title to display it
        // $title = strval($numN);
        
        # Check that we got data
        if($numN==0)
        {
            # No Data
            # Put message into the plot title to display it
            $title = "No Data"; // For testing
        }

        # Initialize data point index
        $i = 0;

        # Set the averaging factor
        if($af == 0) // Use default averaging per MAXSIZE
        {
            # Determine the required averaging factor
            $af = ceil($numN / MAXSIZE);

            # Adjust the analysis tau accordingly
            $tau = $tau * $af;
        }
        else
        {
            # Otherwise, use user-entered AF
        }

        # Manually set AF per AF macro if MANUAL_AF is TRUE
        if(MANUAL_AF)
        {
            $af = AF;
        }

        # For testing - Put $af into title
        // $title = $af;

        # Fetch data and save it in $phase array
        # pg_fetch_data() fetches next row as an array of strings
        # (starting at 1st row) into array $row
        # (there is only 1 item per row, the meas value)
        # and returns zero when there are no more points
        #
        # Downsampling is done by saving the data modulo the AF
        # using the 2nd index $j for the phase array
        $j = 0;

        while($row = pg_fetch_row($result))
        {
            if(($i % $af)==0)
            {
                # Save point in $phase array for data-data plot format
                # The 1st item in each $phase row is a blank label
                # The 2nd item in each $phase row is the data point #
                # It starts at 1 and goes to $numN
                # The 3rd item in each $phase row is the phase value
                $phase[$j][0] = '';
                $phase[$j][1] = $j+1;
                $phase[$j][2] = $row[0];
                
                # Increment array index
                $j++; 
            }
 
            # Increment index
            $i++;
        }
    }

    # For testing, examine the contents of the $phase array
    # The 1st index is the 2-dimensional array row
    # The 2nd index is the 2-dimensional array column
    // $title = strval($phase[0][2]);
}

# ----------------------------------------------------------------------------
# Function to calculate the average frequency offset
# as the slope of the linear regression of the phase data
# Returns $slope
function calc_freq_slope($phase, $tau)
{
    # Output
    GLOBAL $intercept;

    # For testing
    GLOBAL $title;

    # Local variables for sums, slope and intercept
    $x = 0;
    $y = 0;
    $xy = 0;
    $xx = 0;
    $slope = 0;

    # Get # phase data points
    $numN = count($phase);

    # Accumulate sums
    for($i=0; $i<$numN; $i++)
    {
         $x += $i+1;
         $y += $phase[$i][2];
         $xy += ($i+1)*$phase[$i][2];
         $xx += ($i+1)*($i+1);
    }

    # Calculate slope
    $slope = (($numN*$xy) - ($x*$y)) / (($numN*$xx) - ($x*$x));

    # Calculate y intercept
    $intercept = (($y - $slope*$x) / $numN);

    # Scale slope for measurement tau
    $slope /= $tau;

    # For testing - Put slope & intercept into title
    # $title = "Slope = " . $slope . ", " . "Intercept = " . $intercept;

    # Return slope
    return $slope; 
}

# ----------------------------------------------------------------------------
# Function to calculate the average frequency offset
# as the average of the freq data
# Returns $avg
function calc_freq_avg($freq)
{
    GLOBAL $avg;

    # For testing
    GLOBAL $title;

    # Local variable for sum
    $x = 0;

    # Get # freq data points
    $numM = count($freq);

    # Accumulate sum
    for($i=0; $i<$numM; $i++)
    {
         $x += $freq[$i][2];
    }

    # Calculate average
    $avg = $x / $numM;

    # For testing - Put avg freq into title
    // $title = "Avg Freq = " . $avg;

    # Return slope
    return $avg; 
}

# ----------------------------------------------------------------------------
# Function to calculate the Allan deviation
# for the phase data at its data tau (AF=1)
# Returns $sigma
function calc_sigma($phase, $tau)
{
    # For testing
    GLOBAL $title;

    # Local variables for sum
    $s = 0;
    $ss = 0;

    # Get # data phase points
    $numN = count($phase);

    # Accumulate sum
    for($i=0; $i<$numN-2; $i++)
    {
         $s = $phase[$i+2][2] - (2*$phase[$i+1][2]) + $phase[$i][2];
         $ss += $s*$s;
    }

    # Calculate ADEV
    $sigma = sqrt($ss / (2*($numN-2)*$tau*$tau));

    # For testing - Put ADEV into title
    // $title = "ADEV = " . $sigma;

    # Return ADEV
    return $sigma;
}

# ----------------------------------------------------------------------------
# Function to convert phase data to frequency data
# No return (void)
function phase_to_freq($phase, $freq, $tau)
{
    # For testing
    GLOBAL $title;
    GLOBAL $phase;
    GLOBAL $tau;
    GLOBAL $freq;

    # Get # phase data points
    $numN = count($phase);

    # Calculate 1st differences of phase
    # Note: Phase and freq array indices are 0-based
    # but their 1st points are marked 1 in their [0][1] values
    # There is 1 fewer frequency data point (M=N-1)
    for($i=0; $i<$numN-1; $i++)
    {
         $freq[$i][0] = '';
         $freq[$i][1] = $i;
         $freq[$i][2] = ($phase[$i+1][2]-$phase[$i][2])/$tau;
    }

    # Get # freq data points
    # Their array index $i goes from 0 to numM-1 = 0 to numN-2
    # and their $freq[$i][1] label goes from 1 to numm
    $numM = count($freq);

    # For testing - Put 1st freq value into title
    // $title = "freq[10] = " . $freq[$numM-1][2] . ", numN = " . $numN . ", numM = " . $numM;
}

# ----------------------------------------------------------------------------
# The following function scales the phase data to engineering units
# It takes the indexed phase data array as a parameter,
# scales it to engineering units, and returns the units name
# We handle s, ms, us, ns and ps
# If abs data range is < 1000e-12, we use ps and scale by 1e12
# If abs data range is < 1000e-9,  we use ns and scale by 1e9
# if abs data range is < 1000e-6,  we use us and scale by 1e6
# Otherwise, we use ms and scale by 1e3
#
# Function to scale phase data to engineering units
# Returns $units
function scale_phase_data()
{
    # INPUTS
    GLOBAL $phase;

    # OUTPUTS
    GLOBAL $units;
    GLOBAL $factor;
    GLOBAL $numN;
    GLOBAL $title; // For testing

    # Get # data phase points
    $numN = count($phase);

    # Find data range (range = max - min)
    $max = $phase[0][2];
    $min = $max;
    
    # Find max and min of phase data
    for($i=0; $i<$numN; $i++)
    {
        # Find max
        if($phase[$i][2] > $max)
        {
            $max = $phase[$i][2];
        }

        # Find min
        if($phase[$i][2] < $min)
        {
            $min = $phase[$i][2];
        }
    }

    # Get range
    $range = $max - $min;

    # For testing, put the # phase points into the plot title to display it
    // $title = "# Phase Points = " . $numN;

    # For testing, put the max, min & range into the plot title to display it
    // $title = "Max = " . $max;
    // $title = "Min = " . $min;
    // $title = "Range = " . $range;

    # Check for zero range
    if($range==0)
    {
        # If range is zero because only 1 point or all identical points
        # we can still scale the data by setting the range to the 1st point
        $range = $phase[0][2];
    }

     # Determine scale
    if(abs($range) < 1.0e-9)
    {
        $units = "ps";
        $factor = 1.0e12;
    }
    elseif(abs($range) < 1e-6)
    {
        $units = "ns";
        $factor = 1.0e9;
    }
    elseif(abs($range) < 1e-3)
    {
        $units = "us";
        $factor = 1.0e6;
    }
    else
    {
        $units = "ms";
        $factor = 1.0e3;
    }

    # Do scaling
    for($i=0; $i<$numN; $i++)
    {
        $phase[$i][2] *= $factor;
    }

    return $units;
}

# ----------------------------------------------------------------------------
# The following function scales the frequency data to engineering units
# It takes the indexed freq data array as a parameter,
# scales it to engineering units, and returns the units name
# We handle pp10^6 thru pp10^15
# If abs data range is < 1000e-15, we use pp10^15 and scale by 1e15
# If abs data range is < 1000e-12, we use pp10^12 and scale by 1e12
# If abs data range is < 1000e-9,  we use pp10^9 and scale by 1e9
# Otherwise, we use pp10^6 and scale by 1e6
#
# Function to scale freq data to engineering units
# Returns $units
function scale_freq_data()
{
    # INPUTS
    GLOBAL $freq;

    # OUTPUTS
    GLOBAL $units;
    GLOBAL $factor;
    GLOBAL $numM;
    GLOBAL $title; // For testing

    # Get # freq data points
    $numM = count($freq);

    # Find data range (range = max - min)
    $max = $freq[0][2];
    $min = $max;
    
    # Find max and min of freq data
    for($i=0; $i<$numM; $i++)
    {
        # Find max
        if($freq[$i][2] > $max)
        {
            $max = $freq[$i][2];
        }

        # Find min
        if($freq[$i][2] < $min)
        {
            $min = $freq[$i][2];
        }
    }

    # Get range
    $range = $max - $min;

    # For testing, put the # points into the plot title to display it
    // $title = "# Freq Points = " . $numM;

    # For testing, put the max, min & range into the plot title to display it
    // $title = "Max = " . $max . ", Min = " . $min . ", Range = " . $range;

    # Check for zero range
    if($range==0)
    {
        # If range is zero because only 1 point or all identical points
        # we can still scale the data by setting the range to the 1st point
        $range = $freq[0][2];
    }

     # Determine scale
    if(abs($range) < 1.0e-12)
    {
        $units = "pp10^15";
        $factor = 1.0e15;
    }
     if(abs($range) < 1.0e-9)
    {
        $units = "pp10^12";
        $factor = 1.0e12;
    }
    elseif(abs($range) < 1e-6)
    {
        $units = "pp10^9";
        $factor = 1.0e9;
    }
    else
    {
        $units = "pp10^6";
        $factor = 1.0e6;
    }

    # Do scaling
    for($i=0; $i<$numM; $i++)
    {
        $freq[$i][2] *= $factor;
    }

    return $units;
}

# ----------------------------------------------------------------------------
# Write phase data file to disk
# The file folder is set with the FOLDER macro
# You must have read/write permission in that folder
# The file name is set with the FILENAME macro
#
# Function to write phase data file to disk
function write_data($phase, $pg2, $begin, $tau)
{
    # For testing
    GLOBAL $title;

    # Set 1st MJD to bginning MJD as default
    $first = $begin;

    # Check folder write permission
    # before trying to open a file for writing
    # Note: This is the only check made
    # There are other possible reasons for a failure
    # to open the data file that could make the plotting script fail
    $perms = fileperms(FOLDER);
    if(!($perms & 0x0002))
    {
        # For testing
        $title = "No write permission";
        # Silently quit - can't write data file  
        return;
    } 

    # Open data file for reading and writing
    $handle = fopen(FOLDER . FILENAME, "w+");

    # Get # phase data points
    $num = count($phase);

    # Get MJD of 1st phase data point = $first_mjd
    # The begin_mjd is set when the measurement run begins
    # and the 1st data point is writen to the database a little later
    # Use database query:
    # SELECT mjd FROM measurements where mjd > begin_mjd ORDER BY mjd LIMIT 1
    # We already have a database connection $pg2
    # Compose query
    $query = "SELECT mjd FROM measurements WHERE mjd > $begin ORDER BY mjd LIMIT 1";

    # Perform query
    $result = pg_query($pg2, $query);

    # Check result
    if(!$result)
    {
        # Query failed
        # Put message into the plot title to display it
        # Won't show up unless we are debugging
        $title = "Query failed"; // For testing
    }   

    # Get # rows returned - Should be 1
    $numN = pg_num_rows($result);
        
    # Check that we got data
    if($numN!=1)
    {
        # No result
        # Put message into the plot title to display it
        $title = "No Result"; // For testing
    }
    else // Query result OK
    {
        # Get result (the MJD of the 1st data point)
        list($first) = pg_fetch_row($result);
    }

    # Write timetagged phase data to file
    # We get the timetag as the first MJD plus N times tau (in days)
    for($i=0; $i<$num-1; $i++)
    {
        // Compose line of MJD timetag and phase value
        // Note that the phase array index is 1-based
        fwrite($handle, ($first + ($i*$tau/86400.0)) . " " . $phase[$i+1][2] . "\n");
    } 
}

# ----------------------------------------------------------------------------
# Function draw_graph() uses PHPlot to actually produce the graph.
# A PHPlot object is created, set up, and then told to draw the plot.
#
# Function to draw the plot
# No return (void)
function draw_graph()
{
    # INPUTS
    GLOBAL $phase;
    GLOBAL $freq;
    GLOBAL $tau;
    GLOBAL $units;
    GLOBAL $sn;
    GLOBAL $ch;
    GLOBAL $type;
    GLOBAL $w;
    GLOBAL $h;
    GLOBAL $leg1;
    GLOBAL $leg2;
    GLOBAL $leg3;
    GLOBAL $af;
    GLOBAL $avg;
    GLOBAL $numM;
    GLOBAL $slope;
    GLOBAL $intercept;
    GLOBAL $sigma;
    GLOBAL $numN;
    GLOBAL $factor;
    GLOBAL $title; // For testing

    $plot = new PHPlot($w, $h);
    $plot->SetPrintImage(False); // For dual plot
    $plot->SetPlotType('lines');
    # Omit this title setting if using it to display an earlier value
    if($type == 'phase')
    {
        $numM = count($phase);
        // $title = 'PicoPak S/N ' . $sn . $ch . ' Phase Data # = ' . $numN;
        $title = 'PicoPak S/N ' . $sn . $ch . ' Phase Data';
        $label = 'Phase, ' . $units;
        $plot->SetDataValues($phase);

        # Normal (default) legend position at upper right of plot:
        # $plot->SetLegendPosition(1, 0, 'plot', 1, 0, -5, 5);
        # Alternative legend position at upper left of plot:
        # $plot->SetLegendPosition(0, 0, 'plot', 0, 0, 5, 5);
        # Want to use that when phase data is in upper right of plot
        # e.g., when $phase[$numN-1][2] > $phase[0][2]
        if($phase[$numN-1][2] > $phase[0][2])
        {
            $plot->SetLegendPosition(0, 0, 'plot', 0, 0, 5, 5);
        }
    }
    else // Frequency data
    {
        $numM = count($freq);
        // $title = 'PicoPak S/N ' . $sn . $ch . ' Frequency Data # = ' . $numM;
        $title = 'PicoPak S/N ' . $sn . $ch . ' Frequency Data';
        $label = 'Frequency, ' . $units;
        # Use squared plot if frequency plot with fewer than 1000 data points
        if($numM < 1000)
        {
           $plot->SetPlotType('squared');
        }        
        $plot->SetDataValues($freq);

        # Want to use upper left legend position
        # when freq data is significantly in upper right of plot
        # e.g., when $phase[$numN-1][2] > $phase[0][2] by 3 sigma
        if($freq[$numM-1][2] > $freq[0][2] + 3*$sigma*$factor)
        {
            $plot->SetLegendPosition(0, 0, 'plot', 0, 0, 5, 5);
        }
    }

    # For testing
    # $title = ($intercept*$factor) . " " . (($intercept+($slope*$tau*$numN))*$factor);

    $plot->SetTitle($title);
    $plot->SetTitleColor('blue');
    $plot->SetDataType('data-data');
    $plot->SetDataColors(array('red'));
    $plot->SetXTitle('Data Point');
    $plot->SetXLabelType('data', 0);
    $plot->SetYTitle($label);
    $plot->SetYLabelType('data', 2);
    $plot->SetDrawXGrid(True);
    $plot->SetPlotBorderType('full');
    if(SHOW_FREQ)
    {
        $plot->SetLegend($leg1);
    }
    if(SHOW_ADEV)
    {
        $plot->SetLegend($leg2);
    }
    if($af > 1)
    {
        $plot->SetLegend($leg3);
    }
    $plot->SetLegendStyle('right', 'none');
    $plot->DrawGraph(); // Draw main data plot
    # Draw frequency average
    if(SHOW_AVG)
    {
        if($type == 'freq')
        {
            $line=array(array('',1,$avg*$factor),array('',$numM,$avg*$factor));
            $plot->SetDataValues($line);
            $plot->SetDataColors(array('green'));
            $plot->DrawGraph(); // Draw freq avg
        }
    }
    # Draw phase slope
    if(SHOW_SLOPE)
    {
        if($type == 'phase')
        {
            $line=array(array('',1,($intercept*$factor)),
                array('',$numN,(($intercept+($slope*$tau*$numN))*$factor)));
            $plot->SetDataValues($line);
            $plot->SetDataColors(array('green'));
            $plot->DrawGraph(); // Draw phase slope
        }
    }
    $plot->PrintImage(); // Show dual plot
}

# ----------------------------------------------------------------------------
# Lastly, the main code for the image drawing script
# It simply uses the above functions

# This is our main processing code
$pg2 = connect_to_db($db_host, $db_name, $db_user, $db_password);
if(DOWNSAMPLE=='TRUE')
{
    read_and_downsample_data($pg2, $n, $begin);
}
else // No "averaging"
{
    read_data($pg2, $n, $begin);
}
write_data($phase, $pg2, $begin, $tau);
$sigma = calc_sigma($phase, $tau);
if($type == 'freq')
{
    phase_to_freq($phase, $freq, $tau);
    $avg = calc_freq_avg($freq);
    $favg = sprintf("%10.3e", $avg);
    $leg1 = 'Freq = ' . $favg;
    scale_freq_data();
}
else // Phase plot
{
    $slope = calc_freq_slope($phase, $tau);
    $frequency = sprintf("%10.3e", $slope);
    $leg1 = 'Freq = ' . $frequency;
    scale_phase_data();
}
$adev = sprintf("%10.3e", $sigma);
$leg2 = 'ADEV = ' . $adev;
$leg3 = ' Avg Factor =   ' . $af;
draw_graph();

