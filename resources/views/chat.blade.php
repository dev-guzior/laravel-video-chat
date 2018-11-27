@extends('layouts.app')

@section('content')
    <div class="container">

        @if (session('status'))
            <div class="alert alert-success" role="alert">
                {{ session('status') }}
            </div>
        @endif

        <span id="myid"></span>
        <video id="selfview" autoplay></video>
        <video id="remoteview" autoplay></video>
        <button id="endCall" style="display: none;" onclick="endCurrentCall()">End Call</button>
        <div id="list">
            <ul id="users">

            </ul>
        </div>
    </div>
@endsection

@section('js')
    <script src="https://js.pusher.com/4.1/pusher.min.js"></script>
    <script>
        var pusher = new Pusher('{{ env('PUSHER_APP_KEY') }}', {
            cluster: '{{ env('PUSHER_APP_CLUSTER') }}',
            encrypted: true,
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    'X-CSRF-Token': '{{ csrf_token() }}',
                }
            }
        });
        var usersOnline, id, users = [], room, caller, localUserMedia;
        const channel = pusher.subscribe('presence-interview.{{ $chat->hash }}');
        channel.bind('pusher:subscription_succeeded', (members) => {
            //set the member count
            usersOnline = members.count;
            id = channel.members.me.id;
            document.getElementById('myid').innerHTML = ` My caller id is : ` + id;
            members.each((member) => {
                if (member.id != channel.members.me.id) {
                    users.push(member.id)
                }
            });
            render();
        })
        channel.bind('pusher:member_added', (member) => {
            users.push(member.id)
            render();
        });
        channel.bind('pusher:member_removed', (member) => {
            // for remove member from list:
            var index = users.indexOf(member.id);
            users.splice(index, 1);
            if (member.id == room) {
                endCall();
            }
            render();
        });

        function render() {
            var list = '';
            users.forEach(function (user) {
                list += `<li>` + user + ` <input type="button" style="float:right;"  value="Call" onclick="callUser('` + user + `')" id="makeCall" /></li>`
            })
            document.getElementById('users').innerHTML = list;
        }

        //To iron over browser implementation anomalies like prefixes
        GetRTCPeerConnection();
        GetRTCSessionDescription();
        GetRTCIceCandidate();
        prepareCaller();

        function prepareCaller() {
            //Initializing a peer connection
            caller = new window.RTCPeerConnection();
            //Listen for ICE Candidates and send them to remote peers
            caller.onicecandidate = function (evt) {
                if (!evt.candidate) return;
                console.log("onicecandidate called");
                onIceCandidate(caller, evt);
            };
            //onaddstream handler to receive remote feed and show in remoteview video element
            caller.ontrack = function (evt) {
                console.log("onaddstream called");
                document.getElementById("remoteview").srcObject = evt.stream;
            };
        }

        function getCam() {
            //Get local audio/video feed and show it in selfview video element
            return navigator.mediaDevices.getUserMedia({
                video: true,
                audio: true
            });
        }

        function GetRTCIceCandidate() {
            window.RTCIceCandidate = window.RTCIceCandidate || window.webkitRTCIceCandidate ||
                window.mozRTCIceCandidate || window.msRTCIceCandidate;
            return window.RTCIceCandidate;
        }

        function GetRTCPeerConnection() {
            window.RTCPeerConnection = window.RTCPeerConnection || window.webkitRTCPeerConnection ||
                window.mozRTCPeerConnection || window.msRTCPeerConnection;
            return window.RTCPeerConnection;
        }

        function GetRTCSessionDescription() {
            window.RTCSessionDescription = window.RTCSessionDescription || window.webkitRTCSessionDescription ||
                window.mozRTCSessionDescription || window.msRTCSessionDescription;
            return window.RTCSessionDescription;
        }

        //Create and send offer to remote peer on button click
        function callUser(user) {
            getCam()
                .then(stream => {
                    document.getElementById("selfview").srcObject = stream
                    toggleEndCallButton();
                    caller.addStream(stream);
                    localUserMedia = stream;
                    caller.createOffer().then(function (desc) {
                        caller.setLocalDescription(new RTCSessionDescription(desc));
                        channel.trigger("client-sdp", {
                            "sdp": desc,
                            "room": user,
                            "from": id
                        });
                        room = user;
                    });
                })
                .catch(error => {
                    console.log('an error occured', error);
                })
        };

        function endCall() {
            room = undefined;
            caller.close();
            for (let track of localUserMedia.getTracks()) {
                track.stop()
            }
            prepareCaller();
            toggleEndCallButton();
        }

        function endCurrentCall() {

            channel.trigger("client-endcall", {
                "room": room
            });
            endCall();
        }

        //Send the ICE Candidate to the remote peer
        function onIceCandidate(peer, evt) {
            if (evt.candidate) {
                channel.trigger("client-candidate", {
                    "candidate": evt.candidate,
                    "room": room
                });
            }
        }

        function toggleEndCallButton() {
            if (document.getElementById("endCall").style.display == 'block') {
                document.getElementById("endCall").style.display = 'none';
            } else {
                document.getElementById("endCall").style.display = 'block';
            }
        }

        //Listening for the candidate message from a peer sent from onicecandidate handler
        channel.bind("client-candidate", function (msg) {
            if (msg.room == room) {
                console.log("candidate received");


                caller.addIceCandidate(new RTCIceCandidate(msg.candidate));
            }
        });
        //Listening for Session Description Protocol message with session details from remote peer
        channel.bind("client-sdp", function (msg) {
            if (msg.room == id) {

                console.log("sdp received");
                var answer = confirm("You have a call from: " + msg.from + "Would you like to answer?");
                if (!answer) {
                    return channel.trigger("client-reject", {"room": msg.room, "rejected": id});
                }
                room = msg.room;
                getCam()
                    .then(stream => {
                        localUserMedia = stream;
                        toggleEndCallButton();
                        document.getElementById("selfview").srcObject = stream
                        caller.addStream(stream);
                        var sessionDesc = new RTCSessionDescription(msg.sdp);
                        caller.setRemoteDescription(sessionDesc);
                        caller.createAnswer().then(function (sdp) {
                            caller.setLocalDescription(new RTCSessionDescription(sdp));
                            channel.trigger("client-answer", {
                                "sdp": sdp,
                                "room": room
                            });
                        });
                    })
                    .catch(error => {
                        console.log('an error occured', error);
                    })
            }

        });
        //Listening for answer to offer sent to remote peer
        channel.bind("client-answer", function (answer) {
            if (answer.room == room) {
                console.log("answer received");
                caller.setRemoteDescription(new RTCSessionDescription(answer.sdp));
            }

        });
        channel.bind("client-reject", function (answer) {
            if (answer.room == room) {
                console.log("Call declined");
                alert("call to " + answer.rejected + "was politely declined");
                endCall();
            }

        });
        channel.bind("client-endcall", function (answer) {
            if (answer.room == room) {
                console.log("Call Ended");
                endCall();

            }

        });

    </script>
    <style>
        video {
            /* video border */
            width: 200px;
            border: 1px solid #ccc;
            padding: 20px;
            margin: 10px;
            border-radius: 20px;
        }

        #list ul {
            list-style: none;
        }

        #list ul li {
            font-family: Georgia, serif, Times;
            font-size: 18px;
            display: block;
            width: 300px;
            height: 28px;
            background-color: #333;
            border-left: 5px solid #222;
            border-right: 5px solid #222;
            padding-left: 10px;
            text-decoration: none;
            color: #bfe1f1;
        }

        #list ul li:hover {
            box-shadow: 10px 10px 20px #000000;
        }
    </style>
@stop