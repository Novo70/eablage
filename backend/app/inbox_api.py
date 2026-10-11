import logging
from typing import Annotated, Literal
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field
from app.services import inbox
from app.services.classification import classify

router = APIRouter()

class DatabaseRequest(BaseModel):
    database: str = Field(min_length=1, max_length=64)

class JobsRequest(DatabaseRequest):
    filenames: list[Annotated[str, Field(min_length=1, max_length=255)]] | None = Field(default=None, max_length=200)

class FileRequest(DatabaseRequest):
    filename: str = Field(min_length=1, max_length=255)
    retry: bool = False

class ProcessRequest(FileRequest):
    ocr_requested: bool = False

class ExtractRequest(FileRequest):
    scope: Literal['inbox', 'archive'] = 'inbox'
    ocr_requested: bool = False

class ClassificationRequest(BaseModel):
    text: str = Field(max_length=16 * 1024 * 1024)
    exclude_document_id: int | None = None


def call(function, *args):
    try:
        return function(*args)
    except (ValueError, OSError) as exc:
        raise HTTPException(400, str(exc)) from exc
    except Exception as exc:
        logging.exception('Inbox-Anfrage fehlgeschlagen')
        raise HTTPException(503, 'Verarbeitung nicht verfügbar. SQL-Update, Datenpfade und Backend-Protokoll prüfen.') from exc

@router.post('/inbox/process')
def process(request: ProcessRequest):
    return call(inbox.process, request.database, request.filename, request.retry, request.ocr_requested)

@router.post('/inbox/jobs')
def jobs(request: JobsRequest):
    return call(inbox.list_jobs, request.database, request.filenames)

@router.post('/documents/text')
def text(request: ExtractRequest):
    return call(inbox.extract_document, request.database, request.filename, request.scope, request.ocr_requested)

@router.post('/analyze/classify')
def classification(request: ClassificationRequest):
    return call(classify, request.text, request.exclude_document_id)
