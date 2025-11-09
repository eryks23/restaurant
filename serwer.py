from flask import Flask, jsonify, send_from_directory, request
import datetime

app = Flask(__name__, static_folder=".")

# Endpoint API
@app.route("/vectron-utk-sim/api/get-availability.php")
def get_availability():
    start_date = request.args.get("from")
    end_date = request.args.get("to")

    # Przykładowa logika - dostępność dla każdego dnia między 'from' i 'to'
    if not start_date or not end_date:
        return jsonify({"error": "Missing 'from' or 'to' parameter"}), 400

    try:
        start = datetime.datetime.strptime(start_date, "%Y-%m-%d").date()
        end = datetime.datetime.strptime(end_date, "%Y-%m-%d").date()
    except ValueError:
        return jsonify({"error": "Invalid date format. Use YYYY-MM-DD"}), 400

    delta = (end - start).days
    availability = []

    for i in range(delta + 1):
        day = start + datetime.timedelta(days=i)
        availability.append({
            "date": str(day),
            "available": True if i % 3 != 0 else False  # przykładowa logika
        })

    return jsonify({"availability": availability})

# Serwowanie statycznego index.html
@app.route("/", defaults={"path": "index.html"})
@app.route("/<path:path>")
def serve_static(path):
    return send_from_directory(app.static_folder, path)

if __name__ == "__main__":
    app.run(debug=True)
