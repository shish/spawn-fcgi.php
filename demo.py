#!/usr/bin/python

import web

urls = (
    '/', 'index',
    '/(.*)', 'index',
    '', ''
)
app = web.application(urls, globals())

class index:
    def GET(self, name="world"):
        inp = web.input(data="data")
        return """
            <h1>Hello """+name+"""!</h1>
            <br>This is a FastCGI app written with web.py!

            <ul>
                <li>Method: GET
                <li>GET['data']: """+inp.data+"""
            </ul>

            <p><form action="" method="POST">
                <input type="text" name="data">
                <input type="submit">
            </form>
        """

    def POST(self, name="world"):
        inp = web.input(data="data")
        return """
            <h1>Hello """+name+"""!</h1>

            <ul>
                <li>Method: POST
                <li>POST['data']: """+inp.data+"""
            </ul>

            <p><form action="" method="GET">
                <input type="text" name="data">
                <input type="submit">
            </form>
        """

if __name__ == "__main__":
    app.run()
