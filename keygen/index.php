<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Hash Generator</title>
    <script src="libs/bcrypt.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof dcodeIO === 'undefined' || typeof dcodeIO.bcrypt === 'undefined') {
                console.error('bcrypt is not defined. Please ensure that bcrypt.min.js is loaded correctly.');
                return;
            }

            var bcrypt = dcodeIO.bcrypt;

            function generateHash() {
                const password = document.getElementById('password').value;
                if (password.length < 12) {
                    alert('Password must be at least 12 characters long.');
                    return;
                }
                const hash = bcrypt.hashSync(password, 13); // Using a cost factor of 13
                document.getElementById('hash').value = hash;
            }

            document.getElementById('hashForm').onsubmit = function(event) {
                event.preventDefault();
                generateHash();
            };
        });
    </script>
</head>
<body>
    <h1>Password Hash Generator</h1>
    <form id="hashForm">
        <label for="password">Password:</label>
        <input type="text" id="password" name="password" required>
        <button type="submit">Generate Hash</button>
    </form>
    <label for="hash">Hash:</label>
    <input type="text" id="hash" readonly>
</body>
</html>