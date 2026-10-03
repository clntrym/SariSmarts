<?php
require_once("../init.php");
requireRole(['employee']);
include("employee_header.php");
?>

<div class="container">

    <div class="card shadow">

        <div class="card-header bg-primary text-white">
            Register Face
        </div>

        <div class="card-body text-center">

            <video id="video" width="500" autoplay playsinline style="border-radius:10px;border:1px solid #ccc">
            </video>

            <canvas id="canvas" style="display:none;"></canvas>

            <br><br>

            <button class="btn btn-success" onclick="registerFace()">

                Register Face

            </button>

        </div>

    </div>

</div>

<script src="../assets/js/face-api.min.js"></script>

<script>

    async function loadModels() {

        await faceapi.nets.tinyFaceDetector.loadFromUri("../models");

        await faceapi.nets.faceLandmark68Net.loadFromUri("../models");

        await faceapi.nets.faceRecognitionNet.loadFromUri("../models");

    }

    loadModels();

    const video = document.getElementById("video");

    navigator.mediaDevices.getUserMedia({
        video: true
    }).then(stream => {
        video.srcObject = stream;
    });

    async function registerFace() {

        const detection =
            await faceapi
                .detectSingleFace(
                    video,
                    new faceapi.TinyFaceDetectorOptions()
                )
                .withFaceLandmarks()
                .withFaceDescriptor();

        if (!detection) {

            Swal.fire(
                "No Face",
                "Face not detected.",
                "warning"
            );

            return;
        }

        fetch("save_face.php", {

            method: "POST",

            headers: {
                "Content-Type": "application/json"
            },

            body: JSON.stringify({

                descriptor: Array.from(
                    detection.descriptor
                )

            })

        })

            .then(r => r.json())

            .then(data => {

                Swal.fire({

                    icon: data.success ? "success" : "error",

                    title: data.message

                });

            });

    }

</script>

<?php include("employee_footer.php"); ?>