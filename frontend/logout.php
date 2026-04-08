<?php

require __DIR__ . '/app/bootstrap.php';

logout_user();
redirect_to('login.php');
