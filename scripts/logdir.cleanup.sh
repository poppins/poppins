#! /bin/bash
#####################################
# USAGE
#####################################
if [ "$1" == "" ]
then
    echo 'Usage:' $0 ' -l {logdir} -r {rootdir} {-y}'
    exit
fi

#####################################
# ARGUMENTS
#####################################
INTERACTIVE=true

while getopts l:r:y opt; do
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
      # option r
      #####################################
      r)
        ROOTDIR=${OPTARG}
        # validate
        if [[ ! -r $ROOTDIR ]]
        then
          echo Illegal root dir! Cannot access $ROOTDIR!
          exit 1
        fi
      ;;
      #####################################
      # option y
      #####################################
      y)
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
# validate rootdir
if [[ "$ROOTDIR" == "" ]]
then
  echo No root directory specified!
  exit 1
fi
#####################################
# search for orphaned files
#####################################
ARRAY=()
#for f in $(cut -d' ' -f6 $APPLICATIONLOG)
for f in $(ls $LOGDIR | grep -v poppins.log)
do
    logfile=$(echo $f | tr -d '"')
    # check if backup exists
    snapshot=$(echo $(basename $logfile) | rev | cut -d. -f4- | rev)
    # check if logfile exists
    if [[ -f $LOGDIR$logfile  ]]
    then
        # check if snapshot still exists
        if [[ -z $(find $ROOTDIR -name $snapshot -print -quit) ]]
        then
#            echo NO napshot found!
            ARRAY[$[${#ARRAY[@]}]]=$LOGDIR$logfile
#        else
#            echo Snapshot found.
        fi
    fi
done
#####################################
# confirm deletion
#####################################
number_to_delete=${#ARRAY[@]}
DELETE=false
if [[ number_to_delete -gt 0 ]]
then
    echo Orphaned log files:
    for (( i=0;i<${#ARRAY[@]};i++))
    do
        file=${ARRAY[${i}]}
        echo $file
    done
    echo $number_to_delete orphaned log files found.
    if [[ $INTERACTIVE == "true" ]]
    then
        echo 'Do you want to remove these files (y/n)?'
        read ANSW
        if [ "$ANSW" == "y" ]
        then
           DELETE=true
        else
            echo Aborted
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
    for (( i=0;i<${#ARRAY[@]};i++))
    do
        file=${ARRAY[${i}]}
        echo Deleting file $file...
        rm -r $file
    done
fi
echo Done!