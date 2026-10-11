"""Non-writing metadata preparation endpoint; no database dependencies."""
from typing import Any

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, ConfigDict

from app.services.metadata_service import SCHEMA_VERSION, MetadataError, metadata_filename, serialize_metadata

router = APIRouter()


class MetadataRequest(BaseModel):
    model_config = ConfigDict(extra='forbid')
    document: dict[str, Any]


@router.post('/metadata/prepare')
def prepare_metadata(request: MetadataRequest):
    try:
        content = serialize_metadata(request.document)
        return {'filename': metadata_filename(request.document['DokID']),
                'schema_version': SCHEMA_VERSION, 'content': content}
    except MetadataError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
