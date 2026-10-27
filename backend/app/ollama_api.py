"""Manual Ollama actions; all configuration and document text come from the database."""
import logging
from fastapi import APIRouter, HTTPException
from fastapi.responses import JSONResponse
from pydantic import BaseModel, ConfigDict, Field
from app.services import ollama, ollama_background, ki_status

router = APIRouter(prefix='/ollama')


class ConnectionRequest(BaseModel):
    model_config = ConfigDict(extra='forbid')
    database: str = Field(min_length=1, max_length=64)


class SuggestionRequest(ConnectionRequest):
    filename: str = Field(min_length=1, max_length=255)
    sha256: str = Field(pattern=r'^[0-9a-f]{64}$')
    text_sha256: str = Field(pattern=r'^[0-9a-f]{64}$')
    job_id: int = Field(gt=0)
    current_fields: dict[str, str | list[dict[str, str]]] = Field(default_factory=dict, max_length=9)


def call(action, request):
    try:
        return action(**request.model_dump())
    except ollama.OllamaError as exc:
        if hasattr(exc, 'diagnostic'):
            return JSONResponse(status_code=400, content={'detail': str(exc), 'diagnostic': exc.diagnostic})
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    except Exception as exc:
        # Never log model responses or document text.
        logging.error('Ollama-Anfrage fehlgeschlagen (%s)', type(exc).__name__)
        detail = 'KI-Anfrage fehlgeschlagen. Dokumentstatus und Backend-Konfiguration prüfen.'
        if hasattr(exc, 'diagnostic'):
            return JSONResponse(status_code=500, content={'detail': detail, 'diagnostic': exc.diagnostic})
        raise HTTPException(status_code=500, detail=detail) from exc


@router.post('/check')
def check(request: ConnectionRequest):
    return call(ollama.check_connection, request)


@router.post('/suggest')
def suggest(request: SuggestionRequest):
    return call(ollama.suggest, request)


@router.post('/background')
def background(request: SuggestionRequest):
    return call(ki_status.read_with_status, request)

