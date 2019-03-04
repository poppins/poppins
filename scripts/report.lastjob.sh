#!/bin/bash

LOGDIR=/root/poppins.d/logs

while getopts c:l: opt
do
    case $opt in
        l)
            if [[ "$LOGDIR" == "" ]]
            then
                echo 'option -l requires an argument!'
                exit 1
            fi
            LOGDIR="${OPTARG}"
            ;;
        c)
            configdir="${OPTARG}"
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


if [[ "$configdir" == "" ]]
then
    echo 'Need config files directory (use -c </path/to/directory>)'
    exit 1
fi

poppinslog=$LOGDIR/poppins.log
if [[ ! -r $poppinslog ]]
then
  echo Illegal log file! Cannot access $poppinslog!
  exit 1
fi

tmpfile=`mktemp `

for i in $(grep hostdir-name "${configdir}"/*ini | rev | tr -d "'" | cut -f1 -d' ' | sed '/^\s*$/d'| rev | sort | uniq)
do 
    grep $i "$poppinslog" | tail -1
done | sort > $tmpfile

for p in ERROR WARNING NOTICE SUCCESS
do 
    echo ++ $p ++
    echo
    grep $p $tmpfile
    echo
done		

rm $tmpfile
