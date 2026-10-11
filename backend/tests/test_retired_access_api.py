import unittest
from fastapi.testclient import TestClient
from main import app

class RetiredAccessAPI(unittest.TestCase):
    def test_retired_operations_are_not_exposed(self):
        with TestClient(app) as client:
            for operation in ['migration', 'cleanup', 'date']:
                for action in ['preview', 'run']:
                    with self.subTest(operation=operation, action=action):
                        response = client.post(f'/archive/{operation}/{action}', json={'database': 'test'})
                        self.assertEqual(response.status_code, 404)
