#! /bin/bash
#####################################
# USAGE
#####################################
if [ "$1" == "" ]
then
    echo 'Usage:' $0 ' -l {logdir} {-a}'
    exit
fi

#####################################
# ARGUMENTS
#####################################
INTERACTIVE=true

while getopts l:a opt; do
  case $opt in
      #####################################
      # option l
      #####################################
      l)
        LOGDIR=${OPTARG}
        # validate
        APPLICATIONLOG=$LOGDIR/poppins.log
        if [[ ! -r $APPLICATIONLOG ]]
        then
          echo Illegal log file! Cannot access $APPLICATIONLOG!
          exit 1
        fi
      ;;
      #####################################
      # option a
      #####################################
      a)
        INTERACTIVE=false
      ;;
      #####################################
      # illegal options
      #####################################
      \?)
	       echo "Invalid option: -$OPTARG" >&2
	       exit 1
	    ;;
      #####################################
      # required options
      #####################################
      :)
      	echo "Option -$OPTARG requires an argument." >&2
      	exit 1
	    ;;
  esac
done
#####################################
# Check arguments
#####################################
# validate logdir
if [[ "$LOGDIR" == "" ]]
then
  echo No log directory specified!
  exit 1
fi
#####################################
# search for orphaned files
#####################################
# check if error messages
if [[ ! -z $(ls $LOGDIR | grep 'error.log' ) ]]
then
    echo Search error logs...
    echo
    for file in $(ls $LOGDIR | grep 'error.log' )
    do
        echo $LOGDIR/$file
    done
    echo
    echo WARNING! ERROR LOGS FOUND!
    echo
    if [[ $INTERACTIVE == "true" ]]
    then
        echo 'Error logs contain valuable information. Do you want to delete them anyway (y/n)?'
        read ANSW
        echo
        if [ "$ANSW" == "y" ]
        then
            echo Delete all error logs...
            for file in $(ls $LOGDIR | grep 'error.log');
            do
                echo Delete file $LOGDIR/$file...
                rm -r $LOGDIR/$file
            done
        else
            echo Skip error logs...
        fi
    else
        echo Not deleting error logs while in non-interactive mode...
    fi
fi


ARRAY=()
echo
echo Search orphaned log files...
for f in $(ls $LOGDIR | grep -v error.log | grep -v poppins.log)
do
    logfile=$(echo $f | tr -d '"')
    # check if backup exists
    snapshot=$(echo $(basename $logfile) | rev | cut -d. -f4- | rev)
    # check if logfile exists
    if [[ -f $LOGDIR/$logfile ]]
    then
	hostdir="$(zgrep -m 1 -oP '(?<=hostdir = ).*' $LOGDIR/$logfile)"
        # check if snapshot still exists
	#echo -n check dir: $hostdir/archive/*${snapshot}*
	for dir in $hostdir/archive/*${snapshot}*
	do
	    if [[ -d "$dir" ]]
	    then
		continue 2
	    fi
	done
	ARRAY[$[${#ARRAY[@]}]]=$LOGDIR/$logfile
    fi
done
#####################################
# confirm deletion
#####################################
number_to_delete=${#ARRAY[@]}
DELETE=false
if [[ number_to_delete -gt 0 ]]
then
    for (( i=0;i<${#ARRAY[@]};i++))
    do
        file=${ARRAY[${i}]}
        echo $file
    done
    echo
    echo $number_to_delete 'orphaned success|warning log files found!'
    echo
    if [[ $INTERACTIVE == "true" ]]
    then
        echo 'Do you want to remove these files (y/n)?'
        read ANSW
        echo
        if [ "$ANSW" == "y" ]
        then
           DELETE=true
        else
            echo Abort...
            exit
        fi
    else
        DELETE=true
    fi
else
    echo No orphaned files found.
    exit
fi
#####################################
# delete files
#####################################
if [[ $DELETE == "true" ]]
then
    printf "%s\0" "${ARRAY[@]}" | xargs -0 -i rm -v '{}'
fi
