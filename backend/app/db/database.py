import os

import mysql.connector
from dotenv import load_dotenv


load_dotenv()


DB_CONFIG = {
    "host": os.getenv("DB_HOST"),
    "user": os.getenv("DB_USER"),
    "password": os.getenv("DB_PASSWORD"),
    "database": os.getenv("DB_NAME"),
}


def get_connection():
    return mysql.connector.connect(**DB_CONFIG)


def test_autokat_count():
    connection = get_connection()

    try:
        cursor = connection.cursor()
        cursor.execute("SELECT COUNT(*) FROM autokat")
        result = cursor.fetchone()

        return result[0] if result else 0

    finally:
        connection.close()
