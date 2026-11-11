import json
from pathlib import Path
import unittest

from fastapi.testclient import TestClient
from main import app


class ReleaseTests(unittest.TestCase):
    def test_health_and_api_documentation_report_repository_release(self):
        expected = json.loads((Path(__file__).resolve().parents[2] / 'release.json').read_text())['version']
        with TestClient(app) as client:
            response = client.get('/health')
            self.assertEqual(response.status_code, 200)
            self.assertEqual(response.json()['version'], expected)
            self.assertEqual(client.get('/openapi.json').json()['info']['version'], expected)
