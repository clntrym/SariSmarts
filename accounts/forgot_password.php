
<?php
require_once("../init.php");
include("acc_header.php");
?>

<style>

/*==========================
BACKGROUND
==========================*/

body{
    margin:0;
    min-height:100vh;
    font-family:'Poppins',sans-serif;
    background:url('../assets/retailcore_2nd_bg.png') center center/cover no-repeat;
    display:flex;
    justify-content:center;
    align-items:center;
}

/*==========================
CARD
==========================*/

.forgot-container{
    width:100%;
    display:flex;
    justify-content:center;
    align-items:center;
}

.forgot-card{
    width:430px;
    padding:45px;
    border-radius:25px;
    border:1.5px solid #222;
    background:rgba(255,255,255,.30);
    backdrop-filter:blur(8px);
    -webkit-backdrop-filter:blur(8px);
    box-shadow:0 15px 40px rgba(0,0,0,.12);
}

/*==========================
LOGO
==========================*/

.logo{
    width:110px;
    display:block;
    margin:auto;
    margin-bottom:30px;
}

/*==========================
TEXT
==========================*/

h1{
    color:#072A63;
    text-align:center;
    font-weight:700;
    margin-bottom:20px;
}

.description{
    text-align:center;
    color:#666;
    line-height:1.8;
    margin-bottom:35px;
    font-size:15px;
}

label{
    color:#072A63;
    font-weight:600;
}

/*==========================
INPUT
==========================*/

.email-box{
    margin-top:10px;
    height:56px;
    background:#fff;
    border:1.5px solid #999;
    border-radius:18px;
    display:flex;
    align-items:center;
    padding:0 18px;
}

.email-box i{
    font-size:22px;
    color:#555;
    margin-right:15px;
}

.email-box input{
    width:100%;
    border:none;
    outline:none;
    background:transparent;
    font-size:15px;
}

/*==========================
BUTTON
==========================*/

.btn-reset{
    width:100%;
    height:58px;
    margin-top:22px;
    background:#072A63;
    color:#fff;
    border:none;
    border-radius:18px;
    font-size:16px;
    font-weight:600;
    transition:.3s;
}

.btn-reset i{
    margin-right:10px;
}

.btn-reset:hover{
    background:#FDB515;
    color:#072A63;
}

/*==========================
BOTTOM
==========================*/

.bottom-text{
    margin-top:35px;
    text-align:center;
    color:#666;
}

.bottom-text a{
    text-decoration:none;
    font-weight:600;
}

/*==========================
RESPONSIVE
==========================*/

@media(max-width:576px){
    .forgot-card{
        width:92%;
        padding:30px;
    }
}

</style>

<div class="forgot-container">
    <div class="forgot-card">
        <img src="../assets/logo.png" class="logo">
        <h1>Forgot Password?</h1>
        <p class="description">
            No worries! Enter your registered email address and we'll send you
            instructions to reset your password.
        </p>
        <form method="POST">
            <label>Email Address</label>
            <div class="email-box">
                <i class="fa-regular fa-envelope"></i>
                <input
                    type="email"
                    name="email"
                    placeholder="Enter your registered email"
                    required>
            </div>
            <button class="btn-reset">
                <i class="fa-solid fa-paper-plane"></i>
                Send Reset Link
            </button>
        </form>
        <div class="bottom-text">
            Remember your password?
            <a href="acc_log_in.php">Login</a>
        </div>
    </div>
</div>

<?php include("acc_footer.php"); ?>