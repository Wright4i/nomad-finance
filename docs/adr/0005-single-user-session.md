# One user, session cookie

Superseded by [Owner and Demo](0008-owner-and-demo.md).

The server has a single user, created on a setup screen (name, password, base currency). Later logins ask for the password only. The password is stored with `password_hash`. The browser keeps a PHP session cookie: HttpOnly, SameSite=Lax, Secure on HTTPS. Mutating requests send the session CSRF token. Five failed logins lock the form for 15 minutes. There is no signup after setup.
