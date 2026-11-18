# A framed session keeps its cookie

On HTTPS, including a request whose X-Forwarded-Proto or CF-Visitor scheme is https, the session cookie is HttpOnly, Secure, SameSite=None, and Partitioned. A page embedded on another site can then send the same session that issued the form token. SameSite=Lax is kept for plain HTTP, where a browser would reject None. Logout expires both the partitioned cookie and the unpartitioned one.
