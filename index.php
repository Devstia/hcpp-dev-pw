<?php
session_start();
if ( isset( $_SESSION['user'] ) ) {

    // User is logged in take them to quickstart
    header( 'Location: /list/web/?quickstart=main' );
}else{

    // User is logged out take them to login
    header( 'Location: /login/?alt=' . $_GET['alt'] );
}
